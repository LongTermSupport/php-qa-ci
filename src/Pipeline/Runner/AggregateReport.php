<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The end-of-run summary for aggregate (read-only, non-fail-fast) mode.
 *
 * @internal
 */
final readonly class AggregateReport
{
    public function __construct(private OutputInterface $output)
    {
    }

    /** @return bool true when nothing failed */
    public function report(string ...$failedTools): bool
    {
        $this->output->writeln('');
        $this->output->writeln('    ==================================================');
        $this->output->writeln('');
        if ([] === $failedTools) {
            $this->output->writeln('        Aggregate (read-only) run: every QA tool passed.');
            $this->output->writeln('');
            $this->output->writeln('    ==================================================');
            $this->output->writeln('');

            return true;
        }

        $this->output->writeln(\sprintf('        Aggregate (read-only) run: %d tool(s) FAILED', \count($failedTools)));
        $this->output->writeln('');
        foreach ($failedTools as $tool) {
            $this->output->writeln('          - ' . $tool);
        }

        $this->output->writeln('');
        $this->output->writeln('        Defence Before Fix: https://defence-before-fix.github.io/');
        $this->output->writeln('');
        $this->output->writeln("        Each tool's full output is above. Fix every item, then re-run.");
        $this->output->writeln('        (This run did not fail fast: all tools ran so you see every problem.)');
        $this->output->writeln('');
        $this->output->writeln('    ==================================================');
        $this->output->writeln('');

        return false;
    }
}
