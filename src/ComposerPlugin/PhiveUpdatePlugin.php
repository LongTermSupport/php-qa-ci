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
        $this->setupPhiveSymlink($event);
        $this->runPhiveScript('update', $event);
    }

    /**
     * Runs after composer install to install Phive-managed PHARs.
     */
    public function installPhiveDependencies(Event $event): void
    {
        $this->setupPhiveSymlink($event);
        $this->runPhiveScript('install', $event);
    }

    /**
     * Sets up the qaConfig/phive.xml file and symlink from vendor directory.
     *
     * This ensures that:
     * 1. The project's qaConfig/phive.xml exists (copies from phive.xml.dist if needed)
     * 2. A symlink exists from vendor/lts/php-qa-ci/phive.xml to the project's qaConfig/phive.xml
     *
     * This allows projects to manage their own tool versions while keeping the vendor
     * directory free from tracked version changes.
     */
    private function setupPhiveSymlink(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();
        $config = $composer->getConfig();

        $vendorDir = $config->get('vendor-dir');
        if (!is_string($vendorDir)) {
            $io->writeError('<error>Failed to determine vendor directory</error>');

            return;
        }

        // Determine project root (parent of vendor directory)
        $projectRoot = \dirname($vendorDir);
        $qaConfigDir = $projectRoot . '/qaConfig';
        $projectPhiveXml = $qaConfigDir . '/phive.xml';
        $phiveXmlDist = $vendorDir . '/lts/php-qa-ci/phive.xml.dist';
        $vendorPhiveXml = $vendorDir . '/lts/php-qa-ci/phive.xml';

        // Step 1: Ensure qaConfig directory exists
        if (!\is_dir($qaConfigDir)) {
            $io->write('<info>Creating qaConfig directory...</info>');
            if (!\mkdir($qaConfigDir, 0755, true)) {
                $io->writeError('<error>Failed to create qaConfig directory</error>');

                return;
            }
        }

        // Step 2: Ensure project's qaConfig/phive.xml exists
        if (!\file_exists($projectPhiveXml)) {
            if (!\file_exists($phiveXmlDist)) {
                $io->writeError('<error>phive.xml.dist template not found at ' . $phiveXmlDist . '</error>');

                return;
            }

            $io->write('<info>Copying phive.xml.dist to qaConfig/phive.xml...</info>');
            if (!\copy($phiveXmlDist, $projectPhiveXml)) {
                $io->writeError('<error>Failed to copy phive.xml.dist to qaConfig/phive.xml</error>');

                return;
            }
            $io->write('<info>✓ Created qaConfig/phive.xml from template</info>');
        }

        // Step 3: Ensure symlink exists (remove old file if necessary)
        if (\file_exists($vendorPhiveXml) || \is_link($vendorPhiveXml)) {
            // Check if it's already a valid symlink pointing to the right place
            if (\is_link($vendorPhiveXml)) {
                $linkTarget = \readlink($vendorPhiveXml);
                if (false === $linkTarget) {
                    $io->writeError('<error>Failed to read symlink at ' . $vendorPhiveXml . '</error>');

                    return;
                }

                // Normalize paths for comparison
                $expectedTarget = '../../../qaConfig/phive.xml';
                if ($linkTarget === $expectedTarget) {
                    // Symlink is already correct
                    return;
                }

                $io->write('<info>Removing old symlink (points to wrong location)...</info>');
                if (!\unlink($vendorPhiveXml)) {
                    $io->writeError('<error>Failed to remove old symlink</error>');

                    return;
                }
            } else {
                $io->write('<info>Removing old phive.xml file (will be replaced with symlink)...</info>');
                if (!\unlink($vendorPhiveXml)) {
                    $io->writeError('<error>Failed to remove old phive.xml file</error>');

                    return;
                }
            }
        }

        // Create the symlink (relative path from vendor/lts/php-qa-ci to project root)
        $io->write('<info>Creating symlink from vendor to qaConfig/phive.xml...</info>');
        $relativeTarget = '../../../qaConfig/phive.xml';
        if (!\symlink($relativeTarget, $vendorPhiveXml)) {
            $io->writeError('<error>Failed to create symlink</error>');

            return;
        }

        $io->write('<info>✓ Symlink created: vendor/lts/php-qa-ci/phive.xml → qaConfig/phive.xml</info>');
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
