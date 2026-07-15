<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use LTS\PHPQA\ComposerPlugin\PhiveUpdatePlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the plugin's Composer event-subscription wiring. See PhpStanGuardPluginTest
 * for why the map is asserted against literal Composer event-name strings rather
 * than referencing ScriptEvents (composer/composer is not installed; the plugin
 * tree is PHPStan-excluded).
 *
 * @internal
 */
#[CoversClass(PhiveUpdatePlugin::class)]
#[Small]
final class PhiveUpdatePluginTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../assets/ComposerPlugin/composer-stubs.php';
    }

    #[Test]
    public function installAndUpdateRouteToDistinctHandlers(): void
    {
        self::assertSame(
            [
                'post-update-cmd'  => 'onPostUpdate',
                'post-install-cmd' => 'onPostInstall',
            ],
            PhiveUpdatePlugin::getSubscribedEvents(),
        );
    }
}
