<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Composer plugin that automatically updates Phive-managed PHAR dependencies.
 *
 * This plugin hooks into Composer's post-install and post-update events to ensure
 * that PHAR tools managed by Phive (PHPStan, PHP-CS-Fixer, Infection, etc.) are
 * automatically installed or updated whenever composer install/update runs.
 *
 * The plugin runs regardless of whether lts/php-qa-ci itself is being updated,
 * ensuring that consuming projects always have up-to-date PHAR tools.
 */
final class PhiveUpdatePlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // Plugin activation - no initialization needed
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Plugin deactivation - no cleanup needed
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Plugin uninstall - no cleanup needed
    }

    /**
     * @return array<string, string|array{0: string, 1?: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_UPDATE_CMD => 'updatePhiveDependencies',
            ScriptEvents::POST_INSTALL_CMD => 'installPhiveDependencies',
        ];
    }

    /**
     * Runs after composer update to update Phive-managed PHARs.
     */
    public function updatePhiveDependencies(Event $event): void
    {
        $this->runPhiveScript('update', $event);
    }

    /**
     * Runs after composer install to install Phive-managed PHARs.
     */
    public function installPhiveDependencies(Event $event): void
    {
        $this->runPhiveScript('install', $event);
    }

    /**
     * Executes the Phive installation script.
     *
     * @param string $mode Either 'install' or 'update'
     */
    private function runPhiveScript(string $mode, Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();
        $config = $composer->getConfig();

        $vendorDir = $config->get('vendor-dir');
        if (!is_string($vendorDir)) {
            $io->writeError('<error>Failed to determine vendor directory</error>');

            return;
        }

        $scriptPath = $vendorDir . '/lts/php-qa-ci/scripts/phive-install.bash';

        if (!file_exists($scriptPath)) {
            $io->writeError('<comment>Phive install script not found at ' . $scriptPath . ', skipping...</comment>');

            return;
        }

        $io->write('<info>Running Phive ' . $mode . ' for PHAR dependencies...</info>');

        $command = \sprintf(
            'cd %s/lts/php-qa-ci && ./scripts/phive-install.bash %s 2>&1',
            \escapeshellarg($vendorDir),
            \escapeshellarg($mode)
        );

        $output = [];
        $exitCode = 0;
        \exec($command, $output, $exitCode);

        if (0 === $exitCode) {
            $io->write('<info>✓ PHAR dependencies ' . $mode . ' completed successfully</info>');
        } else {
            $io->writeError('<warning>⚠ Phive ' . $mode . ' encountered issues (non-fatal)</warning>');
            if ([] !== $output) {
                foreach ($output as $line) {
                    $io->writeError('  ' . $line);
                }
            }
        }
    }
}
