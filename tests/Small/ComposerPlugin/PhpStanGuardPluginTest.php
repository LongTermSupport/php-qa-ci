<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use LTS\PHPQA\ComposerPlugin\PhpStanGuardPlugin;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\CapturingIO;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\FakeRootPackage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpStanGuardPlugin::class)]
#[Small]
final class PhpStanGuardPluginTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../assets/ComposerPlugin/composer-stubs.php';
    }

    #[Test]
    public function subscribesToInstallAndUpdateWithLowPriority(): void
    {
        self::assertSame(
            [
                ScriptEvents::POST_INSTALL_CMD => ['validatePhpStan', -10],
                ScriptEvents::POST_UPDATE_CMD  => ['validatePhpStan', -10],
            ],
            PhpStanGuardPlugin::getSubscribedEvents(),
        );
    }

    #[Test]
    public function warnsWhenPhpStanIsADirectRequirement(): void
    {
        $io = $this->runValidate(new FakeRootPackage('acme/app', ['phpstan/phpstan' => '^2.0']));

        self::assertStringContainsString('MUST NOT be in your composer.json require', $io->allText());
        self::assertStringContainsString('composer remove phpstan/phpstan', $io->allText());
    }

    #[Test]
    public function warnsWhenPhpStanIsADevRequirement(): void
    {
        $io = $this->runValidate(new FakeRootPackage('acme/app', [], ['phpstan/phpstan' => '^2.0']));

        self::assertStringContainsString('MUST NOT be in your composer.json require-dev', $io->allText());
        self::assertStringContainsString('composer remove --dev phpstan/phpstan', $io->allText());
    }

    #[Test]
    public function isSilentWhenPhpStanIsNotADirectRequirement(): void
    {
        $io = $this->runValidate(new FakeRootPackage('acme/app'));

        self::assertSame('', $io->allText());
    }

    private function runValidate(FakeRootPackage $package): CapturingIO
    {
        $io = new CapturingIO();
        // vendor-dir points at a scratch path with no extension-installer
        // GeneratedConfig, so validatePharVersionConstraint returns early and
        // only the direct-requirement check runs.
        $composer = new Composer(new Config(['vendor-dir' => sys_get_temp_dir() . '/php-qa-ci-no-such-vendor']), $package);
        new PhpStanGuardPlugin()->validatePhpStan(new Event($composer, $io));

        return $io;
    }
}
