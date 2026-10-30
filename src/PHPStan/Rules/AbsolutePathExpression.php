<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast\String_ as StringCast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\MagicConst\File;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use ReflectionClass;
use ReflectionFunctionAbstract;
use SplFileInfo;

/**
 * Decides whether an expression is known to hold a filesystem path, which on a real run is absolute.
 *
 * Known: `__DIR__`, `__FILE__`, the result of realpath(), getcwd(), dirname(), sys_get_temp_dir()
 * or tempnam() (or their Safe\ wrappers), SplFileInfo::getRealPath(), getPathname() and getPath(),
 * a reflection's getFileName(), PHPStan's Scope::getFile(), a string variable or property NAMED as a
 * path (see {@see self::isPathName()}), and a concatenation or interpolation that starts with one.
 * A string cast of any of those is one too, and a nullsafe call or fetch is judged without its null.
 *
 * SplFileInfo::getFilename() is a basename, not a path, and method names are case-insensitive, so
 * a method counts only on the receiver types it is listed for.
 *
 * @internal
 */
final readonly class AbsolutePathExpression
{
    /** @var list<string> functions, lower case and without a Safe\ prefix, that return a path */
    private const array PATH_FUNCTIONS = ['realpath', 'getcwd', 'dirname', 'sys_get_temp_dir', 'tempnam'];

    /** @var array<string, list<class-string>> lower-case method name => the receiver types it returns a path on */
    private const array PATH_METHODS = [
        'getrealpath' => [SplFileInfo::class],
        'getpathname' => [SplFileInfo::class],
        'getpath'     => [SplFileInfo::class],
        'getfilename' => [ReflectionClass::class, ReflectionFunctionAbstract::class, ClassReflection::class],
        'getfile'     => [Scope::class],
    ];

    /** @var list<string> the last word, or last two run together, of a name that says the value is a directory */
    private const array DIRECTORY_LAST_WORDS = ['dir', 'dirname', 'directory', 'workdir', 'cwd', 'folder'];

    /** @var list<string> the last word, or last two run together, of a name that says path, absolute only when qualified */
    private const array PATH_LAST_WORDS = ['path', 'pathname', 'filepath'];

    /** @var list<string> a word in a path name that says the path is absolute: `$absPath`, `$realPath`, `$fullPath` */
    private const array ABSOLUTE_QUALIFIERS = ['abs', 'absolute', 'real', 'full'];

    /** @var string a one-word name for an absolute path, the way realpath() names it */
    private const string REALPATH_WORD = 'realpath';

    /** @var string a word that makes a name a relative path, whatever its other words say */
    private const string RELATIVE_WORD = 'relative';

    /** @var string the last word that says path only after a qualifier: `$projectRoot`, never a bare `$root` */
    private const string ROOT_WORD = 'root';

    /** @var string a word of a name: lower case after any capitals, or capitals alone; underscores separate */
    private const string WORD = '~[A-Z]*[^A-Z_]+|[A-Z]+~';

    /** @var string the namespace prefix of thecodingmachine/safe's wrappers, lower case */
    private const string SAFE_PREFIX = 'safe\\';

    public function isPath(Expr $expr, Scope $scope): bool
    {
        return match (true) {
            $expr instanceof Dir, $expr instanceof File                      => true,
            $expr instanceof StringCast                                      => $this->isPath($expr->expr, $scope),
            $expr instanceof Concat                                          => $this->isPath($expr->left, $scope),
            $expr instanceof InterpolatedString                              => $this->startsWithPath($expr, $scope),
            $expr instanceof FuncCall                                        => $this->isPathFunction($expr),
            $expr instanceof MethodCall, $expr instanceof NullsafeMethodCall => $this->isPathMethod($expr, $scope),
            default                                                          => $this->isPathNamedString($expr, $scope),
        };
    }

    /**
     * True when the name, split on underscores and case changes, states an absolute or root location:
     * a qualified root (`$projectRoot`), a directory (`$cacheDir`, `$workingDirectory`), `$realpath`,
     * or a path qualified as absolute (`$absPath`, `$realPath`, `$absoluteFilePath`). A bare `$path`,
     * `$file` or `$fileName` is as often a URL, a basename or a relative path, and any name with the
     * word `relative` in it says it is not absolute.
     */
    public function isPathName(string $name): bool
    {
        \Safe\preg_match_all(self::WORD, $name, $matches);
        /** @var array{list<string>} $matches */
        $words = array_map(strtolower(...), $matches[0]);
        if (\in_array(self::RELATIVE_WORD, $words, true)) {
            return false;
        }

        $last = end($words);
        if (self::ROOT_WORD === $last) {
            return \count($words) > 1;
        }

        $lastTwo = implode('', \array_slice($words, -2));
        if (self::REALPATH_WORD === $last || \in_array($last, self::DIRECTORY_LAST_WORDS, true) || \in_array($lastTwo, self::DIRECTORY_LAST_WORDS, true)) {
            return true;
        }

        return (\in_array($last, self::PATH_LAST_WORDS, true) || \in_array($lastTwo, self::PATH_LAST_WORDS, true))
            && [] !== array_intersect($words, self::ABSOLUTE_QUALIFIERS);
    }

    private function startsWithPath(InterpolatedString $string, Scope $scope): bool
    {
        $first = $string->parts[0] ?? null;

        return $first instanceof Expr && $this->isPath($first, $scope);
    }

    private function isPathFunction(FuncCall $call): bool
    {
        if (!$call->name instanceof Name) {
            return false;
        }

        $name = $call->name->toLowerString();
        if (str_starts_with($name, self::SAFE_PREFIX)) {
            $name = substr($name, \strlen(self::SAFE_PREFIX));
        }

        return \in_array($name, self::PATH_FUNCTIONS, true);
    }

    private function isPathMethod(MethodCall|NullsafeMethodCall $call, Scope $scope): bool
    {
        if (!$call->name instanceof Identifier) {
            return false;
        }

        $type = TypeCombinator::removeNull($scope->getType($call->var));

        return array_any(self::PATH_METHODS[$call->name->toLowerString()] ?? [], static fn (string $receiver): bool => new ObjectType($receiver)->isSuperTypeOf($type)->yes());
    }

    private function isPathNamedString(Expr $expr, Scope $scope): bool
    {
        $name = match (true) {
            $expr instanceof Variable                                              => $expr->name,
            $expr instanceof PropertyFetch, $expr instanceof NullsafePropertyFetch => $expr->name,
            $expr instanceof StaticPropertyFetch                                   => $expr->name,
            default                                                                => null,
        };
        if ($name instanceof Identifier) {
            $name = $name->toString();
        }

        return \is_string($name) && $this->isPathName($name) && TypeCombinator::removeNull($scope->getType($expr))->isString()->yes();
    }
}
