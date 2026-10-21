<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Defence for the class "an installer stages in the system temp directory and renames into the
 * library" (issue #84; PR #83 met it in TurboInstaller).
 *
 * TemporaryDirectory::create() stages under the system temp directory, which is often another
 * filesystem from the library. A rename() from there copies into the existing file and rewrites
 * it in place, so a process running or mapping that file sees partial bytes, crashes, or makes the
 * copy fail. A file that replaces one in the library is staged with TemporaryDirectory::besides().
 *
 * Every caller of create() in the PHP that ships (src/ and the PHP entry points in bin/) is listed
 * here with the reason the system temp directory is safe for it. A caller missing from the list
 * fails, so a new one is judged when it is written; a listed file that does not call create() fails
 * too, so the list never outlives its reason.
 *
 * A call is found by its resolved class name, so an alias or a relative name is followed, and in
 * every form that can reach the method: a static call, a class or method name held in a variable,
 * an array or string callable, or the class and 'create' passed together as arguments. Where the
 * class or method is only known at run time, a file that names the class anywhere counts as a
 * caller: the guard reports more than it must rather than miss one.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class SystemTempStagingCallersTest extends TestCase
{
    /** The project root; the PHP that ships is under src/ and bin/. */
    private const string ROOT = __DIR__ . '/../../..';

    /** One file per spelling of the call, and per near miss. */
    private const string FIXTURES = __DIR__ . '/../../assets/systemTempStagingCallers';

    /** The method that stages under the system temp directory. */
    private const string METHOD = 'create';

    /**
     * Each file, relative to the project root, that may stage with TemporaryDirectory::create(), and why.
     *
     * @var array<string, string>
     */
    private const array ALLOWED = [
        'src/PhpstanDocs/PhpstanDocsInstaller.php' => 'stages a git checkout it only reads; pages reach the library by copy() into files it has just unlinked, never by rename() from the staging directory, so where it stages decides nothing',
    ];

    #[Test]
    public function everyCallerOfCreateIsListedWithItsReason(): void
    {
        $callers = [];
        foreach ($this->sourceFiles() as $relative => $file) {
            if ($this->callsCreate(\Safe\file_get_contents($file))) {
                $callers[] = $relative;
            }
        }

        sort($callers);
        $allowed = array_keys(self::ALLOWED);
        sort($allowed);

        self::assertSame(
            $allowed,
            $callers,
            'a file that replaces one in the library stages with TemporaryDirectory::besides(); list a create() caller in ALLOWED only with the reason the system temp directory is safe for it',
        );
    }

    /** The guard proven to fire on each spelling of the call, and not on besides() or a comment. */
    #[Test]
    public function theGuardFindsTheCallInEverySpellingAndNothingElse(): void
    {
        $import = '<?php use ' . TemporaryDirectory::class . '; ';
        self::assertTrue($this->callsCreate($import . '$s = TemporaryDirectory::create("x");'));
        self::assertTrue($this->callsCreate('<?php $s = \\' . TemporaryDirectory::class . '::create("x");'));
        self::assertTrue($this->callsCreate('<?php namespace LTS\PHPQA; $s = Filesystem\TemporaryDirectory :: create("x");'));
        self::assertTrue($this->callsCreate($import . '$f = TemporaryDirectory::create(...);'));
        self::assertTrue($this->callsCreate($import . '$s = TemporaryDirectory::CREATE("x");'));
        self::assertTrue($this->callsCreate($import . '$m = "create"; $s = TemporaryDirectory::$m("x");'));
        self::assertTrue($this->callsCreate($import . '$r = new ReflectionMethod(TemporaryDirectory::class, "create");'));
        self::assertFalse($this->callsCreate($import . '$s = TemporaryDirectory::besides($t, "x");'));
        self::assertFalse($this->callsCreate($import . '// TemporaryDirectory::create("x")' . "\n"));
        self::assertFalse($this->callsCreate($import . '$s = OtherDirectory::create("x");'));
        self::assertFalse($this->callsCreate('<?php $s = TemporaryDirectory::create("x");'), 'a class of that name in the global namespace is another class');
    }

    #[Test]
    #[DataProvider('callers')]
    public function theGuardFindsTheCallSpelledAsInTheFixture(string $fixture): void
    {
        self::assertTrue($this->callsCreate(\Safe\file_get_contents($fixture)));
    }

    /**
     * One file per spelling of a call to create() that the guard must find (issue #251).
     *
     * @return iterable<string, array{string}>
     */
    public static function callers(): iterable
    {
        return self::fixtures('callers');
    }

    #[Test]
    #[DataProvider('notCallers')]
    public function theGuardDoesNotReportTheNearMissInTheFixture(string $fixture): void
    {
        self::assertFalse($this->callsCreate(\Safe\file_get_contents($fixture)));
    }

    /**
     * One file per near miss the guard must not report: besides(), or another class's create().
     *
     * @return iterable<string, array{string}>
     */
    public static function notCallers(): iterable
    {
        return self::fixtures('notCallers');
    }

    /** The PHP that ships: src/, and the PHP entry points in bin/, which are named without .php. */
    #[Test]
    public function theGuardSearchesSrcAndThePhpEntryPointsInBin(): void
    {
        $searched = array_keys(iterator_to_array($this->sourceFiles()));

        self::assertContains('src/Filesystem/TemporaryDirectory.php', $searched);
        self::assertContains('bin/qa', $searched, 'a PHP entry point in bin/');
        self::assertContains('bin/bootstrap.php', $searched);
        self::assertNotContains('bin/phpstan', $searched, 'a Bash script in bin/ is not PHP');
    }

    /** @return iterable<string, array{string}> each fixture in the directory, by its name */
    private static function fixtures(string $directory): iterable
    {
        foreach (\Safe\glob(self::FIXTURES . '/' . $directory . '/*.php') as $fixture) {
            if (\is_string($fixture)) {
                yield basename($fixture, '.php') => [$fixture];
            }
        }
    }

    /** Whether the code calls TemporaryDirectory::create(), or may, in any spelling. */
    private function callsCreate(string $code): bool
    {
        // Code that never spells the class's short name cannot name it, short of assembling the
        // name from pieces; parsing only the files that do keeps the search within a small test.
        $class = explode('\\', TemporaryDirectory::class);
        if (false === stripos($code, end($class))) {
            return false;
        }

        $parsed = new ParserFactory()->createForHostVersion()->parse($code);
        self::assertNotNull($parsed);
        $ast = new NodeTraverser(new NameResolver())->traverse($parsed);

        $finder   = new NodeFinder();
        $mentions = $finder->findFirst($ast, $this->namesTheClass(...)) instanceof Node;

        return $finder->findFirst($ast, fn (Node $node): bool => $this->reachesCreate($node, $mentions)) instanceof Node;
    }

    /** Whether the node names the class: a name that resolves to it, or a string that spells it. */
    private function namesTheClass(Node $node): bool
    {
        if ($node instanceof Name) {
            return strtolower($node->toString()) === strtolower(TemporaryDirectory::class);
        }

        return $node instanceof String_ && strtolower(ltrim($node->value, '\\')) === strtolower(TemporaryDirectory::class);
    }

    /**
     * Whether the node calls or names create() on the class. $mentions says whether the file names
     * the class anywhere, which is what a class or callable known only at run time is judged by.
     */
    private function reachesCreate(Node $node, bool $mentions): bool
    {
        if ($node instanceof StaticCall) {
            $onTheClass = $node->class instanceof Name ? $this->namesTheClass($node->class) : $mentions;

            return $onTheClass && (!$node->name instanceof Identifier || $this->isCreate($node->name));
        }

        if ($node instanceof String_) {
            $callable = explode('::', $node->value, 2);

            return 2 === \count($callable)
                && $this->isCreate($callable[1])
                && ('' === $callable[0] ? $mentions : $this->namesTheClass(new String_($callable[0])));
        }

        $values = [];
        if ($node instanceof Array_) {
            foreach ($node->items as $item) {
                $values[] = $item->value;
            }
        } elseif ($node instanceof CallLike) {
            foreach ($node->getRawArgs() as $argument) {
                if ($argument instanceof Node\Arg) {
                    $values[] = $argument->value;
                }
            }
        }

        $namesCreate = [] !== array_filter($values, fn (Expr $value): bool => $value instanceof String_ && $this->isCreate($value->value));
        $mayBeClass  = [] !== array_filter($values, fn (Expr $value): bool => $this->mayNameTheClass($value, $mentions));

        return $namesCreate && $mayBeClass;
    }

    /** Whether the value is the class's name, or may be at run time: anything but a literal or another class's ::class. */
    private function mayNameTheClass(Expr $value, bool $mentions): bool
    {
        if ($value instanceof ClassConstFetch && $value->class instanceof Name) {
            return $this->namesTheClass($value->class);
        }

        if ($value instanceof String_) {
            return $this->namesTheClass($value);
        }

        return $mentions && !$value instanceof Node\Scalar;
    }

    private function isCreate(Identifier|string $name): bool
    {
        return self::METHOD === strtolower($name instanceof Identifier ? $name->toString() : $name);
    }

    /** @return iterable<string, string> each PHP file that ships, by its path relative to the project root */
    private function sourceFiles(): iterable
    {
        $root = \Safe\realpath(self::ROOT);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension()) {
                yield substr($file->getPathname(), \strlen($root) + 1) => $file->getPathname();
            }
        }

        foreach (\Safe\scandir($root . '/bin') as $name) {
            if (!\is_string($name)) {
                continue;
            }

            $file = $root . '/bin/' . $name;
            if (is_file($file) && !is_link($file) && $this->isPhp($file)) {
                yield 'bin/' . $name => $file;
            }
        }
    }

    /** A file PHP runs: it opens with `<?php`, or with a shebang that names php. */
    private function isPhp(string $file): bool
    {
        $handle = \Safe\fopen($file, 'r');

        try {
            $first = fgets($handle);
        } finally {
            \Safe\fclose($handle);
        }

        return false !== $first && (str_starts_with($first, '<?php') || 1 === \Safe\preg_match('{^#!.*\bphp\b}', $first));
    }
}
