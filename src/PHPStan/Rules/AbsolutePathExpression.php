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
 * A string cast of any of those is one too.
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

    /** @var list<string> the last word of a name that says the value is a path */
    private const array PATH_NAME_LAST_WORDS = [
        'path', 'pathname', 'realpath', 'filepath', 'dir', 'dirname', 'directory', 'workdir', 'cwd', 'folder', 'file',
        'filename',
    ];

    /** @var string the last word that says path only after a qualifier: `$projectRoot`, never a bare `$root` */
    private const string ROOT_WORD = 'root';

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

    /** True when the name's last word, or last two run together (`fileName`), split on underscores and case changes, says it holds a path. */
    public function isPathName(string $name): bool
    {
        $snakeCase = '';
        $previous  = '_';
        foreach (str_split($name) as $character) {
            $snakeCase .= (ctype_upper($character) && !ctype_upper($previous) ? '_' : '') . strtolower($character);
            $previous = $character;
        }

        $words = array_values(array_filter(explode('_', $snakeCase), static fn (string $word): bool => '' !== $word));
        $last  = end($words);
        if (false === $last) {
            return false;
        }

        if (self::ROOT_WORD === $last) {
            return \count($words) > 1;
        }

        $lastTwo = implode('', \array_slice($words, -2));

        return \in_array($last, self::PATH_NAME_LAST_WORDS, true) || \in_array($lastTwo, self::PATH_NAME_LAST_WORDS, true);
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

        $name = strtolower(ltrim($call->name->toString(), '\\'));
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

        $type = $scope->getType($call->var);

        return array_any(self::PATH_METHODS[$call->name->toLowerString()] ?? [], static fn ($receiver): bool => new ObjectType($receiver)->isSuperTypeOf($type)->yes());
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

        return \is_string($name) && $this->isPathName($name) && $scope->getType($expr)->isString()->yes();
    }
}
