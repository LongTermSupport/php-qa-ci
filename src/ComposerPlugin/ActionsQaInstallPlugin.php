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
 * Composer plugin that gives a consuming project the pinned actions-qa-ci bundle, which the
 * actionsQa lane runs, on every install/update.
 *
 * Composer runs only the root package's scripts, so php-qa-ci's own scripts/tool-install.bash
 * never runs in a consuming project. This plugin runs `bin/actions-qa-install install` from the
 * installed package instead, as a separate process: the installer's classes are loaded there by
 * the package's own bootstrap, which a plugin running inside Composer cannot rely on mid-install.
 * The plugin decides nothing itself: the installer holds install.sh to its pinned SHA-256,
 * downloads nothing when the pinned release is in place or the project declares
 * withOfflineBuild(true), and prints a notice for a host with no bundle.
 *
 * A failure is reported, never thrown: the project's install goes on, and the actionsQa lane
 * crashes with the install command until the bundle is in place. An installer killed by a signal
 * says nothing, so the plugin then runs the installer's read-only `state` check and relays what
 * it says is on disk.
 *
 * @api Composer is the caller: it constructs the plugin and invokes the
 *      PluginInterface methods and the subscribed event handlers.
 */
final readonly class ActionsQaInstallPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The state check's exit status when the pinned release is not what is installed: it has
     * still said what is on disk. The installer is a separate process whose classes the plugin
     * cannot rely on, so ActionsQaInstaller::EXIT_NOT_CURRENT is repeated here, and a test holds
     * the two equal.
     */
    private const int STATE_NOT_CURRENT = 3;

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
            ScriptEvents::POST_INSTALL_CMD => 'installActionsQa',
            ScriptEvents::POST_UPDATE_CMD  => 'installActionsQa',
        ];
    }

    public function installActionsQa(Event $event): void
    {
        $io       = $event->getIO();
        $composer = $event->getComposer();

        // php-qa-ci as the root package runs scripts/tool-install.bash, which installs the bundle.
        if ('lts/php-qa-ci' === $composer->getPackage()->getName()) {
            return;
        }

        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (!\is_string($vendorDir)) {
            $io->writeError('<error>php-qa-ci: could not determine vendor directory — skipping the actions-qa-ci install</error>');

            return;
        }

        $installer = $vendorDir . '/lts/php-qa-ci/bin/actions-qa-install';
        if (!is_file($installer)) {
            return;
        }

        $executor = new ProcessExecutor($io);
        $exitCode = $executor->execute(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($installer) . ' install');
        if (0 === $exitCode) {
            return;
        }

        $failed = '<comment>php-qa-ci: the actions-qa-ci install failed with ' . ChildExit::describePhpQaCiHelper($exitCode);
        if (!ChildExit::killedBySignal($exitCode)) {
            $io->writeError($failed . "; the installer's output above says what happened</comment>");

            return;
        }

        $io->writeError($failed . '</comment>');
        // Only stdout is captured, and PHP CLI with display_errors on writes warnings there too;
        // sent to stderr, they cannot pass for what the check says is on disk.
        $stateCheck = escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($installer) . ' state';
        $io->writeError('<comment>php-qa-ci: ' . $this->whatTheInstallLeft($executor, $stateCheck) . '</comment>');
    }

    private function whatTheInstallLeft(ProcessExecutor $executor, string $stateCheck): string
    {
        $output   = null;
        $exitCode = $executor->execute($stateCheck, $output);
        $state    = trim(\is_string($output) ? $output : '');
        if ((0 === $exitCode || self::STATE_NOT_CURRENT === $exitCode) && '' !== $state) {
            return 'what the killed install left on disk: ' . $state;
        }

        $why = 0 === $exitCode ? 'printed nothing' : 'failed with ' . ChildExit::describePhpQaCiHelper($exitCode);

        return 'what the killed install left on disk is unknown, because checking it ' . $why . '. Run ' . $stateCheck . ' to see whether the actionsQa lane can run';
    }
}
