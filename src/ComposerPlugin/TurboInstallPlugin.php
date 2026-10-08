<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;

/**
 * Composer plugin that gives a consuming project PHPStan's Turbo binary on every install/update.
 *
 * Composer runs only the root package's scripts, so php-qa-ci's own scripts/tool-install.bash,
 * which fetches Turbo for php-qa-ci itself, never runs in a consuming project. This plugin runs
 * `bin/turbo-install install` from the installed package instead, as a separate process: the
 * installer's classes and the functions they call are loaded there by the package's own
 * bootstrap, which a plugin running inside Composer cannot rely on mid-install. Install mode
 * only fetches what vendor-phar/turbo-ext.json pins; it never rewrites the manifest.
 *
 * A failure is reported, never thrown: PHPStan runs without Turbo, so a failed fetch must not
 * fail the project's install.
 *
 * @api Composer is the caller: it constructs the plugin and invokes the
 *      PluginInterface methods and the subscribed event handlers.
 */
final readonly class TurboInstallPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // No initialisation required.
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No cleanup required.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No cleanup required.
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'installTurbo',
            ScriptEvents::POST_UPDATE_CMD  => 'installTurbo',
        ];
    }

    public function installTurbo(Event $event): void
    {
        $io       = $event->getIO();
        $composer = $event->getComposer();

        // php-qa-ci as the root package runs scripts/tool-install.bash, whose Phase 6 does this.
        if ('lts/php-qa-ci' === $composer->getPackage()->getName()) {
            return;
        }

        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (!\is_string($vendorDir)) {
            $io->writeError('<error>php-qa-ci: could not determine vendor directory — skipping the PHPStan Turbo install</error>');

            return;
        }

        $installer = $vendorDir . '/lts/php-qa-ci/bin/turbo-install';
        if (!is_file($installer)) {
            return;
        }

        $command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($installer) . ' install';
        if (0 !== new ProcessExecutor($io)->execute($command)) {
            $io->writeError('<comment>php-qa-ci: the PHPStan Turbo install failed (see above); PHPStan runs without Turbo</comment>');
        }
    }
}
