<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Defence for the class "PHPStan runs without Turbo and nobody notices".
 *
 * Turbo is PHPStan's native extension. The phar loads it from a `turbo-ext/` directory beside
 * itself, and only a binary built from the commit the phar expects: any other binary, or none,
 * and PHPStan falls back to plain PHP without a word. So this asks the shipped phar itself,
 * invoked the way the phpstan lane invokes it (PhpInvoker: Xdebug off, the memory limit), and
 * requires `diagnose` to report the extension enabled, or, from the PHPStan release whose
 * `diagnose` never restarts and so cannot say "enabled", loaded by the restart an `analyse` run
 * performs with a worker binary that exists on disk.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpstanRunsWithTurboTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string ENABLED = 'Turbo extension: enabled';

    private const string LOADS_ON_RESTART = 'Turbo extension: not loaded here, loaded by the restart';

    private const string WORKER = 'Turbo worker binary: ';

    /** PHPStan's own temp files land here rather than in the shared system temp directory. */
    private TempDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('phpstan-turbo');
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    #[Test]
    public function theShippedPharReportsTurboEnabled(): void
    {
        $php    = new PhpInvoker(new SymfonyProcessRunner(new BufferedOutput()), \PHP_BINARY, '4G', self::REPO_ROOT . '/var/qa');
        $result = $php->withoutXdebug(
            self::REPO_ROOT . '/vendor-phar/phpstan.phar',
            ['diagnose', '--no-interaction'],
            self::REPO_ROOT,
            ['TMPDIR' => $this->tmp->path],
            streamOutput: false,
        );

        self::assertTrue($result->succeeded(), 'phpstan diagnose failed: ' . $result->output);
        $message = \sprintf("The shipped phpstan.phar runs without Turbo. Its diagnose reports:\n%s", $this->turboLines($result->output));

        if (str_contains($result->output, self::ENABLED)) {
            return;
        }

        self::assertStringContainsString(self::LOADS_ON_RESTART, $result->output, $message);
        self::assertFileExists($this->workerBinary($result->output), $message);
    }

    /** The path diagnose names as the binary the restart loads, '' when it names none. */
    private function workerBinary(string $diagnose): string
    {
        foreach (explode("\n", $diagnose) as $line) {
            if (str_starts_with($line, self::WORKER)) {
                return trim(substr($line, \strlen(self::WORKER)));
            }
        }

        return '';
    }

    /** The diagnose lines that explain Turbo's state, for the failure message. */
    private function turboLines(string $diagnose): string
    {
        $lines = array_filter(explode("\n", $diagnose), static fn (string $line): bool => str_starts_with($line, 'Turbo '));

        return implode("\n", $lines);
    }
}
