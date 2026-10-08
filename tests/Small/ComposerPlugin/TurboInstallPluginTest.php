<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use LTS\PHPQA\ComposerPlugin\TurboInstallPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
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
#[Small]
final class TurboInstallPluginTest extends TestCase
{
    private const string COMPOSER_JSON = __DIR__ . '/../../../composer.json';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../assets/ComposerPlugin/composer-stubs.php';
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

    /** Composer loads only the plugin classes composer.json lists. */
    #[Test]
    public function composerJsonRegistersThePlugin(): void
    {
        $composer = \Safe\json_decode(\Safe\file_get_contents(self::COMPOSER_JSON), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['extra'] ?? null);

        self::assertContains(TurboInstallPlugin::class, $composer['extra']['class'] ?? []);
    }
}
