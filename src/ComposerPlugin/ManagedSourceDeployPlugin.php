<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use LTS\PHPQA\ManagedSource\ManagedSourceGenerator;
use Throwable;

/**
 * Composer plugin that (re)generates php-qa-ci's "managed source" tree into the
 * consuming project on every install/update.
 *
 * php-qa-ci OWNS a small set of PHP artefacts that must live in the consumer's
 * own production namespace (e.g. the FactorySealedBy attribute that production
 * code annotates with — it cannot `use` this `require-dev` package). This plugin
 * writes them into a dedicated, locked `<RootNs>\PhpQaCi\` namespace so the
 * consumer never hand-maintains them. The QA pipeline drift-checks the tree.
 *
 * @see ManagedSourceGenerator
 *
 * @api Composer is the caller: it constructs the plugin and invokes the
 *      PluginInterface methods and the subscribed event handlers.
 */
final readonly class ManagedSourceDeployPlugin implements PluginInterface, EventSubscriberInterface
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
            ScriptEvents::POST_INSTALL_CMD => 'generateManagedSource',
            ScriptEvents::POST_UPDATE_CMD  => 'generateManagedSource',
        ];
    }

    public function generateManagedSource(Event $event): void
    {
        $io       = $event->getIO();
        $composer = $event->getComposer();

        if (filter_var(getenv('PHP_QA_CI_DISABLE_CONFIG_PUSH'), FILTER_VALIDATE_BOOLEAN)) {
            $io->write('<info>php-qa-ci: managed-source generation DISABLED via PHP_QA_CI_DISABLE_CONFIG_PUSH=true — skipping</info>');

            return;
        }

        // When php-qa-ci is the ROOT package (its own dev/CI), do not generate
        // into itself — there is no consumer to manage source for.
        if ('lts/php-qa-ci' === $composer->getPackage()->getName()) {
            return;
        }

        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (!\is_string($vendorDir)) {
            $io->writeError('<error>php-qa-ci: could not determine vendor directory — skipping managed-source generation</error>');

            return;
        }

        $projectRoot = \dirname($vendorDir);

        try {
            $written = new ManagedSourceGenerator()->generate($projectRoot);
        } catch (Throwable $throwable) {
            // A project with no autoload.psr-4 has nowhere to host the namespace —
            // skip cleanly rather than failing the whole install/update.
            $io->writeError('<comment>php-qa-ci: managed-source generation skipped — ' . $throwable->getMessage() . '</comment>');

            return;
        }

        foreach ($written as $path) {
            $io->write('<info>php-qa-ci: generated managed source ' . $path . '</info>');
        }
    }
}
