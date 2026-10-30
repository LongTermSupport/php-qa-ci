<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\ComposerNormalize;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "the shipped composer-normalize PHAR embeds a Composer older than the one
 * that wrote the project's lock" (#383).
 *
 * The PHAR bundles its own Composer. When a project's composer.json sets `config.policy` and the
 * lock was written by a newer Composer, an older bundled Composer judges the lock stale and the
 * composerChecks lane fails on a lock nobody can fix. So a lock written by the installed Composer
 * must not be reported stale by the shipped PHAR.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ShippedNormalizePharAcceptsTheInstalledComposersLockTest extends TestCase
{
    private const string PHAR = __DIR__ . '/../../../vendor-phar/composer-normalize.phar';

    private const string MINIMUM_COMPOSER = '2.11.0';

    private ?TempDir $project = null;

    protected function tearDown(): void
    {
        $this->project?->remove();
    }

    #[Test]
    public function aLockWrittenByTheInstalledComposerIsNotStaleWhenConfigPolicyIsSet(): void
    {
        $composer = new ExecutableFinder()->find('composer');
        if (null === $composer) {
            self::markTestSkipped('composer is not on the PATH, so no lock can be written');
        }

        $version = $this->execute([\PHP_BINARY, $composer, '--version', '--no-ansi'], sys_get_temp_dir(), []);
        $matches = [];
        \Safe\preg_match('/Composer version (\d+\.\d+\.\d+)/', $version, $matches);
        $installed = $matches[1] ?? '0';
        if (version_compare($installed, self::MINIMUM_COMPOSER, '<')) {
            self::markTestSkipped(\sprintf('the installed Composer %s is older than %s, which is where the lock check differs', $installed, self::MINIMUM_COMPOSER));
        }

        $this->project = TempDir::create('normalize-policy');
        $root          = $this->project->path;
        $environment   = [
            'COMPOSER_HOME'      => $this->project->mkdir('home'),
            'COMPOSER_CACHE_DIR' => $this->project->mkdir('cache'),
            'COMPOSER_NO_AUDIT'  => '1',
        ];
        $this->project->write('composer.json', \Safe\json_encode([
            'name'   => 'fixture/app',
            'type'   => 'project',
            'config' => ['policy' => ['cooldown' => ['period' => '7 days']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->execute([\PHP_BINARY, '-d', 'display_errors=stderr', $composer, 'update', '--no-interaction', '--no-scripts', '--no-progress'], $root, $environment);

        $output = $this->execute([\PHP_BINARY, '-d', 'display_errors=stderr', self::PHAR, '--dry-run', '--no-interaction', '--no-ansi', 'composer.json'], $root, $environment, false);

        self::assertStringNotContainsString('lock file is not up to date', $output, 'the shipped normalize PHAR rejects a lock the installed Composer ' . $installed . ' wrote');
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $environment
     */
    private function execute(array $argv, string $cwd, array $environment, bool $mustSucceed = true): string
    {
        $process = new Process($argv, $cwd, $environment);
        try {
            $process->run();
        } finally {
            $process->stop();
        }

        if ($mustSucceed && !$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('%s failed: %s', implode(' ', $argv), $process->getErrorOutput() . $process->getOutput()));
        }

        return $process->getOutput() . $process->getErrorOutput();
    }
}
