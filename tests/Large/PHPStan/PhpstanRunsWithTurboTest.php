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
 * requires `diagnose` to report the extension enabled.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpstanRunsWithTurboTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string ENABLED = 'Turbo extension: enabled';

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
        self::assertStringContainsString(self::ENABLED, $result->output, \sprintf(
            "The shipped phpstan.phar runs without Turbo. Its diagnose reports:\n%s",
            $this->turboLines($result->output),
        ));
    }

    /** The diagnose lines that explain Turbo's state, for the failure message. */
    private function turboLines(string $diagnose): string
    {
        $lines = array_filter(explode("\n", $diagnose), static fn (string $line): bool => str_starts_with($line, 'Turbo '));

        return implode("\n", $lines);
    }
}
