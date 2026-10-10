<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support\TempLeak;

use LTS\PHPQA\Tests\Support\TempLeak\TempLeakDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * TempLeakExtension needs a temp directory of its own per test process, made
 * TMPDIR before anything resolves sys_get_temp_dir(). Under Infection the
 * mutant bootstrap reads a compressed entry out of infection.phar before any
 * extension starts, and PHP resolves the temp directory doing so (issue #122),
 * so the directory is claimed when PHPUnit's runner loads the autoloader.
 *
 * @internal
 */
#[CoversNothing]
#[Medium]
final class TempLeakDirectoryTest extends TestCase
{
    private const string MARKER = 'php-qa-ci-tests-';

    #[Test]
    public function thisRunsTempDirectoryIsTheOneClaimedForIt(): void
    {
        $claimed = TempLeakDirectory::claim();

        self::assertSame(sys_get_temp_dir(), $claimed);
        self::assertSame($claimed, TempLeakDirectory::claim(), 'claimed once per process');
        self::assertStringContainsString(self::MARKER . \Safe\getmypid() . '-', $claimed);
    }

    #[Test]
    public function aPhpunitProcessClaimsItBeforeAPharReadResolvesTheTempDirectory(): void
    {
        $output = $this->child(<<<'PHP_WRAP'
            define('PHPUNIT_COMPOSER_INSTALL', $argv[1] . '/vendor/autoload.php');
            require PHPUNIT_COMPOSER_INSTALL;
            // What Infection's mutant bootstrap does first: read a compressed entry from its phar.
            file_get_contents('phar://' . $argv[1] . '/vendor-phar/infection.phar/vendor/infection/include-interceptor/src/IncludeInterceptor.php');
            echo sys_get_temp_dir(), "\n", getenv('TMPDIR');
            PHP_WRAP);

        [$resolved, $tmpdir] = explode("\n", $output);
        self::assertSame($tmpdir, $resolved);
        self::assertStringContainsString(self::MARKER, $resolved);
        self::assertDirectoryDoesNotExist($resolved, 'a process whose extension never started removes its empty directory');
    }

    #[Test]
    public function anyOtherProcessIsLeftAlone(): void
    {
        $output = $this->child(<<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            echo sys_get_temp_dir();
            PHP);

        self::assertSame(sys_get_temp_dir(), $output, "it inherits this process's TMPDIR and claims none of its own");
    }

    private function child(string $code): string
    {
        $process = new Process([\PHP_BINARY, '-r', $code, \dirname(__DIR__, 4)]);
        $process->mustRun();

        return $process->getOutput();
    }
}
