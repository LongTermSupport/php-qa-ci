<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use LTS\PHPQA\ComposerPlugin\PhpStanGuardPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the plugin's Composer event-subscription wiring.
 *
 * The Composer runtime API (composer/composer) is NOT installed in this package
 * — only composer-plugin-api, which ships no classes — so the plugin's methods
 * cannot be exercised through real Composer objects here, and the src/ComposerPlugin
 * tree is deliberately excluded from PHPStan for the same reason (qaConfig/phpstan.neon).
 * To keep this test analysable by the project's own PHPStan gate we therefore do
 * NOT reference any Composer\* type in the test body: getSubscribedEvents() is
 * asserted against the literal Composer event-name strings (the stable values of
 * ScriptEvents::POST_INSTALL_CMD / POST_UPDATE_CMD). The minimal Composer surface
 * needed to LOAD the plugin at runtime (its implemented interfaces + ScriptEvents)
 * is provided by the fake required below, which lives under tests/assets/ so the
 * pipeline ignores it.
 *
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
    public function subscribesToInstallAndUpdateRunningValidationAtLowPriority(): void
    {
        // Low priority (-10) so it runs AFTER PhiveUpdatePlugin has installed the phars.
        self::assertSame(
            [
                'post-install-cmd' => ['validatePhpStan', -10],
                'post-update-cmd'  => ['validatePhpStan', -10],
            ],
            PhpStanGuardPlugin::getSubscribedEvents(),
        );
    }
}
