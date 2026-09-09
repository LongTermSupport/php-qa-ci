<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The run: preflight, lock, then either one tool or the four phases in
 * order, with the retry, aggregate and gate policies applied; hooks around
 * it; the lock released with the exit code.
 *
 * Fail-fast (writable) runs stop at the first failing tool. Aggregate
 * (read-only) runs let every tool run and report the failures together.
 *
 * @internal
 */
final readonly class Pipeline
{
    public function __construct(
        private ToolRegistry $registry,
        private ToolExecutor $executor,
        private RunLock $lock,
        private HookRunner $hooks,
        private DirectoryPreparer $directories,
        private PharToolsVerifier $phars,
        private AggregateReport $aggregate,
        private OutputInterface $output,
        private string $hostname,
        private int $pid,
    ) {
    }

    public function run(ToolContext $context): int
    {
        $config = $context->config;
        $this->directories->prepare($config->paths);
        $this->phars->verify($config->paths->libraryRoot);
        $this->hooks->runPre($context);

        $lockTool = null === $config->singleTool ? 'full pipeline' : $config->singleTool;
        $lockPath = null === $config->specifiedPath ? 'whole project' : $config->specifiedPath;
        if (!$this->lock->acquire($lockTool, $lockPath, $this->hostname, $this->pid)) {
            return 1;
        }

        $exitCode = $this->runLocked($context);
        $this->lock->release($exitCode);

        return $exitCode;
    }

    private function runLocked(ToolContext $context): int
    {
        $config  = $context->config;
        $failed  = [];
        $retried = false;

        if (null !== $config->singleTool) {
            $definition = $this->registry->definition($config->singleTool);
            $this->banner(\sprintf('Running Single Tool: %s', $definition->name), '-');
            $tools = $definition->isPhaseRunner && $definition->phase instanceof PhaseEnum
                ? $this->toolsFor($definition->phase, $config->platform)
                : [$definition];
            $ok = $this->runTools($tools, $context, $failed, $retried, gated: $definition->isPhaseRunner);

            return $this->finish($ok, $failed, $retried, $config->aggregate);
        }

        foreach (PhaseEnum::cases() as $phase) {
            $this->banner($phase->banner(), '=');
            if (!$this->runTools($this->toolsFor($phase, $config->platform), $context, $failed, $retried, gated: true)) {
                return $this->finish(false, $failed, $retried, $config->aggregate);
            }
        }

        if ($config->aggregate && !$this->aggregate->report(...$failed)) {
            return 1;
        }

        $this->successBanner();
        $this->hooks->runPost($context);
        $this->retryWarning($retried);

        return 0;
    }

    /**
     * @param list<ToolDefinitionDto> $tools
     * @param list<string>            $failed
     *
     * @return bool false when a tool failed in fail-fast mode
     */
    private function runTools(array $tools, ToolContext $context, array &$failed, bool &$retried, bool $gated): bool
    {
        $config = $context->config;
        foreach ($tools as $tool) {
            if ($gated && !$tool->gate->allows($config->quickTests, $config->infection->enabled)) {
                $this->output->writeln('');
                $this->output->writeln(\sprintf('Skipping %s (gate=%s, phpqaQuickTests=%d, useInfection=%d)', $tool->name, $tool->gate->name, (int)$config->quickTests, (int)$config->infection->enabled));
                $this->output->writeln('');

                continue;
            }

            if (null !== $tool->banner) {
                $this->banner($tool->banner, '-');
            }

            $this->lock->touch();
            $execution = $this->executor->execute($tool->name, $context);
            $retried   = $retried || $execution->retried;
            if ($execution->result->isSuccess()) {
                continue;
            }

            if (!$config->aggregate) {
                return false;
            }

            $failed[] = $tool->name;
            $this->output->writeln('');
            $this->output->writeln(\sprintf('>>> %s FAILED — continuing (aggregate mode); see the summary at the end.', $tool->name));
            $this->output->writeln('');
        }

        return true;
    }

    /**
     * A phase's generic lanes followed by the platform's own (Symfony's twig
     * and yaml linters after the generic linting lanes).
     *
     * @return list<ToolDefinitionDto>
     */
    private function toolsFor(PhaseEnum $phase, PlatformEnum $platform): array
    {
        return [...$this->registry->toolsForPhase($phase), ...ToolRegistry::platformLanes($platform, $phase)];
    }

    /** @param list<string> $failed */
    private function finish(bool $ok, array $failed, bool $retried, bool $aggregate): int
    {
        if ($aggregate) {
            $ok = $this->aggregate->report(...$failed);
        }

        $this->retryWarning($retried);

        return $ok ? 0 : 1;
    }

    private function banner(string $title, string $underline): void
    {
        $this->output->writeln('');
        $this->output->writeln('');
        $this->output->writeln($title);
        $this->output->writeln(str_repeat($underline, max(24, \strlen($title))));
        $this->output->writeln('');
    }

    private function successBanner(): void
    {
        $this->output->writeln(<<<'BANNER'


            ###############################
            #                             #
            #      ALL TESTS PASSING      #
            #                             #
            #            _                #
            #           /(|               #
            #          (  :               #
            #         __\  \  _____       #
            #       (____)  `|            #
            #      (____)|   |            #
            #       (____).__|            #
            #        (___)__.|_____       #
            #                             #
            #                             #
            ###############################


            BANNER);
    }

    private function retryWarning(bool $retried): void
    {
        if (!$retried) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('####################################################################');
        $this->output->writeln('WARNING - RAN WITH RETRIES');
        $this->output->writeln('As you have restarted steps in this run, run the whole process again to be sure everything is fine.');
        $this->output->writeln('####################################################################');
    }
}
