<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use LTS\PHPQA\Pipeline\Agent\TerseReporter;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lock\Dto\LockInfoDto;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The run: preflight, lock, then either one tool or the registry's phases in
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
    /**
     * Contention is not a verdict on the code. A caller that cannot tell it
     * apart from exit 1 reports someone else's run as this project's defect,
     * which is what a consumer's editor hook did. 75 is the conventional
     * EX_TEMPFAIL: nothing is wrong, try again.
     */
    public const int EXIT_LOCK_CONTENDED = 75;

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
            return $config->agentMode ? $this->reportLockHeld($context, $lockTool) : self::EXIT_LOCK_CONTENDED;
        }

        // A lane that throws must not leave the lock behind: the next run would
        // wait out the stale window for a holder that no longer exists.
        $exitCode = 1;

        try {
            $exitCode = $this->runLocked($context);
        } finally {
            $this->lock->release($exitCode);
        }

        return $exitCode;
    }

    private function runLocked(ToolContext $context): int
    {
        $config  = $context->config;
        $failed  = [];
        $retried = false;

        if ($config->agentMode && null !== $config->singleTool) {
            return $this->runAgentTool($context, $config->singleTool);
        }

        if (null !== $config->singleTool) {
            $definition = $this->registry->definition($config->singleTool);
            $this->banner(\sprintf('Running Single Tool: %s', $definition->name), '-');
            $tools = $definition->isPhaseRunner && null !== $definition->phase
                ? $this->toolsFor($definition->phase, $config->platform)
                : [$definition];
            $ok = $this->runTools($context, $failed, $retried, $definition->isPhaseRunner, ...$tools);

            return $this->finish($ok, $retried, $config->aggregate, ...$failed);
        }

        foreach ($this->registry->phases() as $phase) {
            $this->banner($phase->banner, '=');
            if (!$this->runTools($context, $failed, $retried, true, ...$this->toolsFor($phase->name, $config->platform))) {
                return $this->finish(false, $retried, $config->aggregate, ...$failed);
            }
        }

        if ($config->aggregate && !$this->aggregate->report(...$failed)) {
            return 1;
        }

        $this->successBanner();
        $this->executor->execute('phpcpd', $context);
        $this->hooks->runPost($context);
        $this->retryWarning($retried);

        return 0;
    }

    /**
     * One lane, no banner, no retry, and the agent-mode exit code rather than
     * the pipeline's pass/fail pair. The lane has already written its report
     * and its terse stdout; all that is left is to say which of the four
     * outcomes it was in a way a calling hook can branch on.
     */
    private function runAgentTool(ToolContext $context, string $tool): int
    {
        $this->lock->touch();
        $outcome = $this->executor->execute($tool, $context)->result->outcome;

        return match ($outcome) {
            ToolOutcomeEnum::Crashed => AgentStatusEnum::Crashed->exitCode(),
            ToolOutcomeEnum::Failed  => AgentStatusEnum::Errors->exitCode(),
            default                  => AgentStatusEnum::Clean->exitCode(),
        };
    }

    /**
     * Another run holds the lock, so nothing was analysed. Agent mode says so
     * on stdout and with its own exit code, because the alternative — the
     * generic failure 1 with the explanation on a discarded decoration stream
     * — is indistinguishable from the file being full of errors.
     */
    private function reportLockHeld(ToolContext $context, string $tool): int
    {
        $holder = $this->lock->current();
        new TerseReporter($context->stdout)->lockHeld($tool, $holder instanceof LockInfoDto ? $holder->describe() : 'holder unknown');

        return AgentStatusEnum::LockHeld->exitCode();
    }

    /**
     * @param list<string> $failed
     *
     * @return bool false when a tool failed in fail-fast mode
     */
    private function runTools(ToolContext $context, array &$failed, bool &$retried, bool $gated, ToolDefinitionDto ...$tools): bool
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
    private function toolsFor(string $phase, PlatformEnum $platform): array
    {
        return [...$this->registry->toolsForPhase($phase), ...ToolRegistry::platformLanes($platform, $phase)];
    }

    private function finish(bool $ok, bool $retried, bool $aggregate, string ...$failed): int
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
