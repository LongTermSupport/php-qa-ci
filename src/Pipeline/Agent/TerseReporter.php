<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Everything agent mode puts on the real stdout.
 *
 * The budget is three lines, because the caller is a hook that injects the
 * whole stream into an agent's context after every single edit. What the
 * lines carry is a verdict, a path and — when there is work to do — an
 * instruction, never a finding: a summarised finding invites the agent to fix
 * what it thinks it read instead of opening the report and fixing what is
 * actually there.
 *
 * Refusing an unsupported tool is NOT here. That happens in ArgumentsParser,
 * before a run exists at all, and its message is the usage error.
 *
 * @internal
 */
final readonly class TerseReporter
{
    private const string REPORT_PREFIX = 'REPORT: ';

    private const string ACTION = 'ACTION REQUIRED: read that JSON report NOW and fix every error it lists, then re-run. Do not guess from this summary — it contains no findings, only counts.';

    public function __construct(private OutputInterface $stdout)
    {
    }

    /**
     * The ordinary outcome: a count, the report to read, and the instruction
     * when it is not clean. `$noun` names what was counted, because a lane
     * that reports architecture violations should not call them errors.
     */
    public function result(string $tool, AgentStatusEnum $status, int $errors, int $files, string $reportPath, string $noun = 'error'): void
    {
        $this->stdout->writeln(\sprintf(
            '%s: %s in %s',
            $this->heading($tool),
            $this->plural($errors, $noun),
            $this->plural($files, 'file'),
        ));
        $this->stdout->writeln(self::REPORT_PREFIX . $reportPath);

        if (AgentStatusEnum::Clean !== $status) {
            $this->stdout->writeln(self::ACTION);
        }
    }

    /**
     * Another run holds the project lock, so nothing was analysed. Said
     * plainly, because the one conclusion that must never be drawn here is
     * that the code is clean.
     */
    public function lockHeld(string $tool, string $holder): void
    {
        $this->stdout->writeln(\sprintf('%s: %s — another QA run holds the project lock (%s).', $this->heading($tool), AgentStatusEnum::LockHeld->value, $holder));
        $this->stdout->writeln('No analysis ran and no report was written; the previous report is still the last real result. Wait and re-run.');
    }

    /** The tool died. The report records it too, so the agent has one place to look. */
    public function crashed(string $tool, string $reason, string $reportPath): void
    {
        $this->stdout->writeln(\sprintf('%s: %s — %s', $this->heading($tool), AgentStatusEnum::Crashed->value, $reason));
        $this->stdout->writeln(self::REPORT_PREFIX . $reportPath);
        $this->stdout->writeln(self::ACTION);
    }

    private function heading(string $tool): string
    {
        return strtoupper($tool) . ' AGENT MODE';
    }

    private function plural(int $count, string $noun): string
    {
        return \sprintf('%d %s%s', $count, $noun, 1 === $count ? '' : 's');
    }
}
