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
 * Composer plugin that automatically deploys Claude Code Skills and Agents.
 *
 * This plugin hooks into Composer's post-install and post-update events to deploy
 * php-qa-ci's Skills and Agents to the project's .claude/ directory.
 *
 * Skills are model-invoked entry points that delegate to specialized agents.
 * Agents are task executors launched via Task tool with appropriate model sizes.
 */
final class SkillsDeployPlugin implements PluginInterface, EventSubscriberInterface
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
            ScriptEvents::POST_INSTALL_CMD => 'deploySkills',
            ScriptEvents::POST_UPDATE_CMD => 'deploySkills',
        ];
    }

    /**
     * Deploys Skills and Agents to project's .claude/ directory.
     */
    public function deploySkills(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();
        $config = $composer->getConfig();

        $vendorDir = $config->get('vendor-dir');
        if (!is_string($vendorDir)) {
            $io->writeError('<error>Failed to determine vendor directory</error>');

            return;
        }

        // Determine paths
        $projectRoot = \dirname($vendorDir);
        $qaciPath = $vendorDir . '/lts/php-qa-ci';
        $scriptPath = $qaciPath . '/scripts/deploy-skills.bash';

        // Check if skills/agents exist in php-qa-ci
        if (!\is_dir($qaciPath . '/.claude/skills') && !\is_dir($qaciPath . '/.claude/agents')) {
            $io->write('<comment>No Claude Code Skills/Agents found in php-qa-ci, skipping deployment...</comment>');

            return;
        }

        if (!file_exists($scriptPath)) {
            $io->writeError('<error>Skills deployment script not found at ' . $scriptPath . '</error>');

            return;
        }

        $io->write('<info>Deploying Claude Code Skills and Agents...</info>');

        $command = \sprintf(
            '%s %s %s 2>&1',
            \escapeshellarg($scriptPath),
            \escapeshellarg($qaciPath),
            \escapeshellarg($projectRoot)
        );

        $output = [];
        $exitCode = 0;
        \exec($command, $output, $exitCode);

        // Display output
        foreach ($output as $line) {
            $io->write('  ' . $line);
        }

        if (0 === $exitCode) {
            $io->write('<info>✓ Claude Code Skills & Agents deployed successfully</info>');
        } else {
            $io->writeError('<error>✗ Skills deployment failed</error>');
        }
    }
}
