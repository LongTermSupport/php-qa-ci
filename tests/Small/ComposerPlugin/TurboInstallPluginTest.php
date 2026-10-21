<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event;
use Composer\Util\ProcessExecutor;
use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\ComposerPlugin\TurboInstallPlugin;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\CapturingIO;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\FakeRootPackage;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the plugin's Composer event-subscription wiring. Composer never runs a dependency's own
 * scripts, so this subscription is the only way a consuming project's install reaches
 * bin/turbo-install. See PhpStanGuardPluginTest for why the map is asserted against literal
 * Composer event-name strings.
 *
 * @internal
 */
#[CoversClass(TurboInstallPlugin::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class TurboInstallPluginTest extends TestCase
{
    private const string COMPOSER_JSON = __DIR__ . '/../../../composer.json';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../assets/ComposerPlugin/composer-stubs.php';
    }

    protected function tearDown(): void
    {
        ProcessExecutor::$exitCode = 1;
    }

    #[Test]
    public function bothInstallAndUpdateInstallTurbo(): void
    {
        self::assertSame(
            [
                'post-install-cmd' => 'installTurbo',
                'post-update-cmd'  => 'installTurbo',
            ],
            TurboInstallPlugin::getSubscribedEvents(),
        );
    }

    /**
     * Some failures leave a binary phpstan.phar still loads, so the plugin cannot know whether
     * PHPStan runs without Turbo; only the installer's own lines can say what it left in place.
     */
    #[Test]
    public function aFailedInstallIsReportedWithoutClaimingWhatPhpstanLoads(): void
    {
        self::assertSame(
            ["<comment>php-qa-ci: the PHPStan Turbo install failed with exit code 1; the installer's output above says what happened</comment>"],
            $this->installExitingWith(1),
        );
    }

    /**
     * A killed installer prints nothing about its death, so the line names the signal and points
     * at no output; a segfault is named as one, to be reported, and the install still goes on.
     */
    #[Test]
    public function anInstallerKilledByASignalIsReportedByItsSignal(): void
    {
        self::assertSame(
            ['<comment>php-qa-ci: the PHPStan Turbo install failed with exit code 139, killed by signal 11 (SIGSEGV): it crashed with a segmentation fault, which is never retried past: halt, debug and report it, as https://github.com/LongTermSupport/php-qa-ci/blob/php8.5/CLAUDE/segfault-policy.md describes</comment>'],
            $this->installExitingWith(139),
        );
    }

    #[Test]
    public function aSuccessfulInstallReportsNothing(): void
    {
        self::assertSame([], $this->installExitingWith(0));
    }

    /** Composer loads only the plugin classes composer.json lists. */
    #[Test]
    public function composerJsonRegistersThePlugin(): void
    {
        $composer = \Safe\json_decode(\Safe\file_get_contents(self::COMPOSER_JSON), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['extra'] ?? null);

        self::assertContains(TurboInstallPlugin::class, $composer['extra']['class'] ?? []);
    }

    /** @return list<string> what the plugin wrote to stderr */
    private function installExitingWith(int $exitCode): array
    {
        ProcessExecutor::$exitCode = $exitCode;
        $vendor                    = TempDir::create('turbo-plugin-vendor');

        try {
            $vendor->write('lts/php-qa-ci/bin/turbo-install', '<?php');
            $io = new CapturingIO();
            new TurboInstallPlugin()->installTurbo(new Event(new Composer(new Config(['vendor-dir' => $vendor->path]), new FakeRootPackage()), $io));
        } finally {
            $vendor->remove();
        }

        return $io->err;
    }
}
