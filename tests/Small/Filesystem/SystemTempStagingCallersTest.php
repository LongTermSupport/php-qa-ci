<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use PhpToken;
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
 * Every caller of create() under src/ is listed here with the reason the system temp directory is
 * safe for it. A caller missing from the list fails, so a new one is judged when it is written; a
 * listed file that does not call create() fails too, so the list never outlives its reason.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class SystemTempStagingCallersTest extends TestCase
{
    /** The production source every caller is searched for in. */
    private const string SOURCE =__DIR__ . '/../../../src';

    /** One file per spelling of the call, and per near miss. */
    private const string FIXTURES = __DIR__ . '/../../assets/systemTempStagingCallers';

    /**
     * Each file under src/ that may stage with TemporaryDirectory::create(), and why.
     *
     * @var array<string, string>
     */
    private const array ALLOWED = [
        'PhpstanDocs/PhpstanDocsInstaller.php' => 'stages a git checkout it only reads; pages reach the library by copy() into files it has just unlinked, never by rename() from the staging directory, so where it stages decides nothing',
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
        self::assertTrue($this->callsCreate('<?php $s = TemporaryDirectory::create("x");'));
        self::assertTrue($this->callsCreate('<?php $s = ' . TemporaryDirectory::class . '::create("x");'));
        self::assertTrue($this->callsCreate('<?php $s = Filesystem\TemporaryDirectory :: create("x");'));
        self::assertTrue($this->callsCreate('<?php $f = TemporaryDirectory::create(...);'));
        self::assertFalse($this->callsCreate('<?php $s = TemporaryDirectory::besides($t, "x");'));
        self::assertFalse($this->callsCreate('<?php // TemporaryDirectory::create("x")' . "\n"));
        self::assertFalse($this->callsCreate('<?php $s = OtherDirectory::create("x");'));
    }

    /**
     * One file per spelling of a call to create() that the guard must find (issue #251).
     *
     * @return iterable<string, array{string}>
     */
    public static function callers(): iterable
    {
        foreach (\Safe\glob(self::FIXTURES . '/callers/*.php') as $fixture) {
            yield basename($fixture, '.php') => [$fixture];
        }
    }

    /**
     * One file per near miss the guard must not report: besides(), or another class's create().
     *
     * @return iterable<string, array{string}>
     */
    public static function notCallers(): iterable
    {
        foreach (\Safe\glob(self::FIXTURES . '/notCallers/*.php') as $fixture) {
            yield basename($fixture, '.php') => [$fixture];
        }
    }

    #[Test]
    #[DataProvider('callers')]
    public function theGuardFindsTheCallSpelledAsInTheFixture(string $fixture): void
    {
        self::assertTrue($this->callsCreate(\Safe\file_get_contents($fixture)));
    }

    #[Test]
    #[DataProvider('notCallers')]
    public function theGuardDoesNotReportTheNearMissInTheFixture(string $fixture): void
    {
        self::assertFalse($this->callsCreate(\Safe\file_get_contents($fixture)));
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

    /** Whether the code calls TemporaryDirectory::create(), by its class's short name in any spelling. */
    private function callsCreate(string $code): bool
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $class = explode('\\', TemporaryDirectory::class);
        $short = end($class);

        foreach ($tokens as $index => $token) {
            if (!$token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $segments = explode('\\', $token->text);
            if (end($segments) !== $short) {
                continue;
            }

            $next   = $tokens[$index + 1] ?? null;
            $method = $tokens[$index + 2] ?? null;
            if (null !== $next && $next->is(\T_DOUBLE_COLON) && null !== $method && $method->is(\T_STRING) && 'create' === strtolower($method->text)) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string, string> each PHP file under src/, by its path relative to src/ */
    private function sourceFiles(): iterable
    {
        $root = \Safe\realpath(self::SOURCE);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension()) {
                yield substr($file->getPathname(), \strlen($root) + 1) => $file->getPathname();
            }
        }
    }
}
