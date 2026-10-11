<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support\TempLeak;

use LTS\PHPQA\Tests\Support\TempDir;
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

    private const string PARENT = 'temp-leak-reap';

    private const string RANDOM = '-0123abcd';

    private const string KEPT = 'kept';

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

    /**
     * Infection kills a mutant run that times out with SIGKILL, so its shutdown
     * function never removes its directory (issue #126). The next process to
     * claim one under the same parent removes it, with whatever it holds.
     */
    #[Test]
    public function aClaimRemovesTheDirectoryOfADeadProcess(): void
    {
        $parent = TempDir::create(self::PARENT);
        try {
            $dead      = $this->deadPid();
            $abandoned = $parent->path . '/' . self::MARKER . $dead . self::RANDOM;
            $parent->write(self::MARKER . $dead . '-0123abcd/phpqa-ctx-abc/qaConfig/phpstan.neon', "parameters:\n");
            $parent->write(self::MARKER . $dead . '-0123abcd/stray', '');

            $this->claimUnder($parent->path);

            self::assertFileDoesNotExist($abandoned);
        } finally {
            $parent->remove();
        }
    }

    #[Test]
    public function aClaimLeavesTheDirectoryOfALiveProcessAlone(): void
    {
        $parent = TempDir::create(self::PARENT);
        try {
            $live = $parent->write(self::MARKER . \Safe\getmypid() . '-0123abcd/phpqa-ctx-abc/file', self::KEPT);

            $this->claimUnder($parent->path);

            self::assertFileExists($live);
        } finally {
            $parent->remove();
        }
    }

    #[Test]
    public function aClaimLeavesEveryNameOutsideThePatternAlone(): void
    {
        $parent = TempDir::create(self::PARENT);
        try {
            $dead  = $this->deadPid();
            $names = [
                self::MARKER . $dead,
                self::MARKER . $dead . '-0123abcd-extra',
                self::MARKER . $dead . '-0123ABCD',
                self::MARKER . $dead . '-0123abc',
                self::MARKER . 'abc-0123abcd',
                'x' . self::MARKER . $dead . self::RANDOM,
                'other-' . $dead . self::RANDOM,
            ];
            foreach ($names as $name) {
                $parent->write($name . '/file', self::KEPT);
            }

            $parent->write(self::MARKER . $dead . '-89abcdef.file', 'a file, not a directory');

            $this->claimUnder($parent->path);

            foreach ($names as $name) {
                self::assertFileExists($parent->path . '/' . $name . '/file');
            }

            self::assertFileExists($parent->path . '/' . self::MARKER . $dead . '-89abcdef.file');
        } finally {
            $parent->remove();
        }
    }

    /** A link is never followed: neither one with a matching name, nor one inside a dead process's directory. */
    #[Test]
    public function aClaimNeverFollowsASymlink(): void
    {
        $parent = TempDir::create(self::PARENT);
        $target = TempDir::create('temp-leak-reap-target');
        try {
            $dead = $this->deadPid();
            $kept = $target->write('kept/file', self::KEPT);
            \Safe\symlink($target->path . '/kept', $parent->path . '/' . self::MARKER . $dead . self::RANDOM);
            $parent->mkdir(self::MARKER . $dead . '-89abcdef');
            \Safe\symlink($target->path . '/kept', $parent->path . '/' . self::MARKER . $dead . '-89abcdef/link');

            $this->claimUnder($parent->path);

            self::assertFileExists($kept);
            self::assertDirectoryDoesNotExist($parent->path . '/' . self::MARKER . $dead . '-89abcdef');
        } finally {
            $parent->remove();
            $target->remove();
        }
    }

    /** Two processes claiming at once race to remove the same directories; neither fails or warns. */
    #[Test]
    public function twoClaimsAtOnceBothSucceedQuietly(): void
    {
        $parent = TempDir::create(self::PARENT);
        try {
            $dead = $this->deadPid();
            for ($i = 0; $i < 40; ++$i) {
                for ($j = 0; $j < 10; ++$j) {
                    $parent->write(\sprintf('%s%d-%08x/phpqa-ctx-%d/sub/file%d', self::MARKER, $dead, $i, $j, $j), '');
                }
            }

            $claims = [$this->claimProcess($parent->path), $this->claimProcess($parent->path)];
            try {
                foreach ($claims as $claim) {
                    $claim->start();
                }

                foreach ($claims as $claim) {
                    $claim->wait();
                }
            } finally {
                foreach ($claims as $claim) {
                    $claim->stop(0);
                }
            }

            foreach ($claims as $claim) {
                self::assertSame(0, $claim->getExitCode(), $claim->getErrorOutput());
                self::assertSame('', $claim->getOutput() . $claim->getErrorOutput(), 'no notice or warning');
            }

            self::assertSame(['.', '..'], \Safe\scandir($parent->path));
        } finally {
            $parent->remove();
        }
    }

    /** A pid that was alive a moment ago and is not now: a process started and killed as Infection kills one. */
    private function deadPid(): int
    {
        $process = new Process(['sleep', '30']);
        try {
            $process->start();
            $pid = $process->getPid();
            self::assertIsInt($pid);
            $process->signal(9);
            $process->wait();
        } finally {
            $process->stop(0);
        }

        self::assertDirectoryDoesNotExist('/proc/' . $pid);

        return $pid;
    }

    /** Claims a temp directory in a PHPUnit-like child whose TMPDIR is $parent, and expects it to say nothing. */
    private function claimUnder(string $parent): void
    {
        $process = $this->claimProcess($parent);
        $process->mustRun();

        self::assertSame('', $process->getOutput() . $process->getErrorOutput(), 'no notice or warning');
    }

    private function claimProcess(string $parent): Process
    {
        return new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', <<<'PHP_WRAP'
                define('PHPUNIT_COMPOSER_INSTALL', $argv[1] . '/vendor/autoload.php');
                require PHPUNIT_COMPOSER_INSTALL;
                PHP_WRAP, \dirname(__DIR__, 4)],
            null,
            ['TMPDIR' => $parent],
        );
    }

    private function child(string $code): string
    {
        $process = new Process([\PHP_BINARY, '-r', $code, \dirname(__DIR__, 4)]);
        $process->mustRun();

        return $process->getOutput();
    }
}
