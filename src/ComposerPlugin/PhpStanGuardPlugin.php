<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Semver\Semver;

/**
 * Composer plugin that guards against PHPStan version mismatches and duplicate installs.
 *
 * php-qa-ci provides PHPStan via a PHIVE-managed phar and declares
 * "replace": {"phpstan/phpstan": "*"} to prevent it being installed as a composer package.
 * Rector is installed in an isolated sub-composer project (tools/rector/) so its
 * phpstan/phpstan dependency doesn't leak into the project.
 *
 * Projects must NOT add phpstan/phpstan directly to their own require or require-dev.
 *
 * This plugin:
 * 1. Warns if a project has phpstan/phpstan in its own require or require-dev
 * 2. Validates the phar version satisfies the extension-installer's version constraint
 * 3. Provides clear instructions when issues are found
 */
final class PhpStanGuardPlugin implements PluginInterface, EventSubscriberInterface
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
            ScriptEvents::POST_INSTALL_CMD => ['validatePhpStan', -10],
            ScriptEvents::POST_UPDATE_CMD  => ['validatePhpStan', -10],
        ];
    }

    /**
     * Validates PHPStan setup after install/update.
     *
     * Runs with low priority (-10) to execute after PhiveUpdatePlugin has installed/updated phars.
     */
    public function validatePhpStan(Event $event): void
    {
        $io       = $event->getIO();
        $composer = $event->getComposer();

        $this->checkForDirectPhpStanRequirement($io, $composer);
        $this->validatePharVersionConstraint($io, $composer);
    }

    /**
     * Checks if the root project directly requires phpstan/phpstan, which it should not.
     */
    private function checkForDirectPhpStanRequirement(IOInterface $io, Composer $composer): void
    {
        $rootPackage = $composer->getPackage();
        $requires    = $rootPackage->getRequires();
        $devRequires = $rootPackage->getDevRequires();

        if (isset($requires['phpstan/phpstan'])) {
            $io->writeError('');
            $io->writeError('<error>╔══════════════════════════════════════════════════════════════╗</error>');
            $io->writeError('<error>║  phpstan/phpstan MUST NOT be in your composer.json require  ║</error>');
            $io->writeError('<error>╚══════════════════════════════════════════════════════════════╝</error>');
            $io->writeError('');
            $io->writeError('  php-qa-ci provides PHPStan via a managed PHAR binary.');
            $io->writeError('  Having phpstan/phpstan as a direct dependency causes version conflicts.');
            $io->writeError('');
            $io->writeError('  Fix: <comment>composer remove phpstan/phpstan</comment>');
            $io->writeError('');
        }

        if (isset($devRequires['phpstan/phpstan'])) {
            $io->writeError('');
            $io->writeError('<error>╔════════════════════════════════════════════════════════════════════╗</error>');
            $io->writeError('<error>║  phpstan/phpstan MUST NOT be in your composer.json require-dev   ║</error>');
            $io->writeError('<error>╚════════════════════════════════════════════════════════════════════╝</error>');
            $io->writeError('');
            $io->writeError('  php-qa-ci provides PHPStan via a managed PHAR binary.');
            $io->writeError('  Having phpstan/phpstan as a direct dependency causes version conflicts.');
            $io->writeError('');
            $io->writeError('  Fix: <comment>composer remove --dev phpstan/phpstan</comment>');
            $io->writeError('');
        }
    }

    /**
     * Validates the PHAR version satisfies the extension-installer's constraint.
     */
    private function validatePharVersionConstraint(IOInterface $io, Composer $composer): void
    {
        $config    = $composer->getConfig();
        $vendorDir = $config->get('vendor-dir');

        if (!\is_string($vendorDir)) {
            return;
        }

        $generatedConfigPath = $vendorDir . '/phpstan/extension-installer/src/GeneratedConfig.php';
        if (!file_exists($generatedConfigPath)) {
            // No extensions installed, nothing to validate
            return;
        }

        $pharPath = $vendorDir . '/lts/php-qa-ci/vendor-phar/phpstan.phar';
        if (!file_exists($pharPath)) {
            $io->writeError('<warning>PHPStan phar not found at ' . $pharPath . '. Run: composer update</warning>');

            return;
        }

        // Get phar version
        $pharVersion = $this->getPharVersion($pharPath);
        if (null === $pharVersion) {
            $io->writeError('<warning>Could not determine PHPStan phar version</warning>');

            return;
        }

        // Get extension constraint from GeneratedConfig
        $constraint = $this->getExtensionConstraint($generatedConfigPath);
        if (null === $constraint) {
            // No constraint means no extensions or no version requirement
            return;
        }

        // Use Composer's Semver library for correct version comparison
        if (!Semver::satisfies($pharVersion, $constraint)) {
            $io->writeError('');
            $io->writeError('<error>╔══════════════════════════════════════════════════════════════════╗</error>');
            $io->writeError('<error>║  PHPStan phar version does not satisfy extension constraints   ║</error>');
            $io->writeError('<error>╚══════════════════════════════════════════════════════════════════╝</error>');
            $io->writeError('');
            $io->writeError('  Phar version:        <comment>' . $pharVersion . '</comment>');
            $io->writeError('  Extension constraint: <comment>' . $constraint . '</comment>');
            $io->writeError('');
            $io->writeError('  The PHPStan phar bundled with php-qa-ci is too old for the');
            $io->writeError('  installed PHPStan extensions.');
            $io->writeError('');
            $io->writeError('  Fix: Update lts/php-qa-ci to a newer version:');
            $io->writeError('    <comment>composer update lts/php-qa-ci</comment>');
            $io->writeError('');
            $io->writeError('  If already on the latest version, ask the php-qa-ci maintainer');
            $io->writeError('  to update the bundled PHPStan phar.');
            $io->writeError('');
        } else {
            $io->write('<info>✓ PHPStan phar v' . $pharVersion . ' satisfies extension constraint ' . $constraint . '</info>');
        }
    }

    /**
     * Extracts the PHPStan phar version by running it.
     */
    private function getPharVersion(string $pharPath): ?string
    {
        $output   = [];
        $exitCode = 0;
        \Safe\exec('php ' . escapeshellarg($pharPath) . ' --version 2>/dev/null', $output, $exitCode);

        if (0 !== $exitCode || [] === $output) {
            return null;
        }

        // Output: "PHPStan - PHP Static Analysis Tool 2.1.40"
        foreach ($output as $line) {
            if (1 === \Safe\preg_match('/(\d+\.\d+\.\d+)/', $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Reads the version constraint from the extension-installer's GeneratedConfig.
     */
    private function getExtensionConstraint(string $generatedConfigPath): ?string
    {
        $contents = \Safe\file_get_contents($generatedConfigPath);
        if (false === $contents) {
            return null;
        }

        // Match: public const PHPSTAN_VERSION_CONSTRAINT = '>=2.1.39.0-dev, <3.0.0.0-dev';
        if (1 === \Safe\preg_match("/PHPSTAN_VERSION_CONSTRAINT\\s*=\\s*'([^']+)'/", $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
