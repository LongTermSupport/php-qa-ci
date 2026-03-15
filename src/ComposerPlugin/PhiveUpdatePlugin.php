<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RuntimeException;

/**
 * Composer plugin that ensures isolated tool dependencies are installed.
 *
 * PHARs (PHPStan, PHP-CS-Fixer, Infection, etc.) are committed to the repo
 * in vendor-phar/ and require no installation step.
 *
 * Isolated composer tools (Rector) have their vendor/ gitignored and need
 * `composer install` on first use. This plugin handles that automatically
 * during composer install/update in consuming projects.
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
            ScriptEvents::POST_UPDATE_CMD  => 'onPostUpdate',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
        ];
    }

    /**
     * After composer update: update isolated tools.
     */
    public function onPostUpdate(Event $event): void
    {
        $this->ensureIsolatedTools('update', $event);
    }

    /**
     * After composer install: install isolated tools if missing.
     */
    public function onPostInstall(Event $event): void
    {
        $this->ensureIsolatedTools('install', $event);
    }

    /**
     * Ensures isolated composer tools (Rector) are installed.
     *
     * PHARs are committed to the repo and always present — no phive needed.
     * Only Rector needs its vendor/ installed via composer.
     *
     * @param string $mode Either 'install' or 'update'
     */
    private function ensureIsolatedTools(string $mode, Event $event): void
    {
        $io       = $event->getIO();
        $composer = $event->getComposer();
        $config   = $composer->getConfig();

        $vendorDir = $config->get('vendor-dir');
        if (!\is_string($vendorDir)) {
            $io->writeError('<error>Failed to determine vendor directory</error>');

            return;
        }

        $rectorDir = $vendorDir . '/lts/php-qa-ci/tools/rector';
        $rectorBin = $rectorDir . '/vendor/bin/rector';

        if ('update' === $mode) {
            // During update, refresh rector dependencies
            $io->write('<info>Updating isolated Rector installation...</info>');
            $this->runComposerInDirectory($rectorDir, 'update', $io);
        } elseif (!file_exists($rectorBin)) {
            // During install, only install if rector binary is missing
            $io->write('<info>Installing isolated Rector...</info>');
            $this->runComposerInDirectory($rectorDir, 'install', $io);
        }
    }

    /**
     * Runs composer install/update in a subdirectory.
     */
    private function runComposerInDirectory(string $directory, string $command, IOInterface $io): void
    {
        if (!is_dir($directory)) {
            $io->writeError('<error>Directory not found: ' . $directory . '</error>');

            return;
        }

        $composerJson = $directory . '/composer.json';
        if (!file_exists($composerJson)) {
            $io->writeError('<error>No composer.json found in ' . $directory . '</error>');

            return;
        }

        $shellCommand = \sprintf(
            'composer %s --working-dir=%s --no-interaction --no-dev 2>&1',
            $command,
            escapeshellarg($directory),
        );

        $output   = [];
        $exitCode = 0;
        \Safe\exec($shellCommand, $output, $exitCode);

        if (0 === $exitCode) {
            $io->write('<info>✓ Rector ' . $command . ' completed successfully</info>');

            return;
        }

        $io->writeError('<error>Rector ' . $command . ' failed (exit code ' . $exitCode . ')</error>');
        if ([] !== $output) {
            foreach ($output as $line) {
                $io->writeError('  ' . $line);
            }
        }

        throw new RuntimeException(
            'Rector ' . $command . ' failed in ' . $directory . '. '
            . 'The QA pipeline requires Rector for automated refactoring.'
        );
    }
}
