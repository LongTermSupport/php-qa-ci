<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Filesystem;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
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
    private const string SOURCE = __DIR__ . '/../../../src';

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
        self::assertTrue($this->callsCreate('<?php $s = \LTS\PHPQA\Filesystem\TemporaryDirectory::create("x");'));
        self::assertTrue($this->callsCreate('<?php $s = Filesystem\TemporaryDirectory :: create("x");'));
        self::assertTrue($this->callsCreate('<?php $f = TemporaryDirectory::create(...);'));
        self::assertFalse($this->callsCreate('<?php $s = TemporaryDirectory::besides($t, "x");'));
        self::assertFalse($this->callsCreate('<?php // TemporaryDirectory::create("x")' . "\n"));
        self::assertFalse($this->callsCreate('<?php $s = OtherDirectory::create("x");'));
    }

    /** Whether the code calls TemporaryDirectory::create(), by its class's short name in any spelling. */
    private function callsCreate(string $code): bool
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
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
