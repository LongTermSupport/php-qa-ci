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
 * A failure is reported, never thrown: Turbo only speeds PHPStan up, so a failed install must not
 * fail the project's install. Some failures leave a binary phpstan.phar still loads, so the
 * report claims only the failure and how the installer ended; the installer's own output says
 * what it left in place. An installer killed by a signal says nothing, so the plugin then runs
 * the installer's read-only `state` check and relays what it says is on disk.
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

        $executor = new ProcessExecutor($io);
        $command  = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($installer);
        $exitCode = $executor->execute($command . ' install');
        if (0 === $exitCode) {
            return;
        }

        $failed = '<comment>php-qa-ci: the PHPStan Turbo install failed with ' . ChildExit::describePhpQaCiHelper($exitCode);
        if (!ChildExit::killedBySignal($exitCode)) {
            $io->writeError($failed . "; the installer's output above says what happened</comment>");

            return;
        }

        // A killed installer printed nothing, not even what it left on disk, which can be a binary
        // beside its core that nothing vouches for; the installer's read-only state check says.
        $io->writeError($failed . '</comment>');
        $io->writeError('<comment>php-qa-ci: ' . $this->whatTheInstallLeft($executor, $command . ' state') . '</comment>');
    }

    private function whatTheInstallLeft(ProcessExecutor $executor, string $stateCheck): string
    {
        $output   = null;
        $exitCode = $executor->execute($stateCheck, $output);
        $state    = trim(\is_string($output) ? $output : '');
        if (0 === $exitCode && '' !== $state) {
            return 'what the killed install left on disk: ' . $state;
        }

        $why = 0 === $exitCode ? 'printed nothing' : 'failed with ' . ChildExit::describePhpQaCiHelper($exitCode);

        return 'what the killed install left on disk is unknown, because checking it ' . $why . '. Run ' . $stateCheck . ' to see whether phpstan.phar will load a Turbo binary that nothing vouches for';
    }
}
