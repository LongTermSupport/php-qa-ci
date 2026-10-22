<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;
use LTS\PHPQA\Pipeline\Process\SignalledChildren;
use LTS\PHPQA\Pipeline\Runner\Dto\ExecutionDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one tool with the retry policy: a failure prints the standard banner
 * and, interactively, offers a retry; a crash is never retried. A lane that ran
 * a child a signal killed crashed, named with the signal (SignalledChildren).
 *
 * @internal
 */
final readonly class ToolExecutor
{
    /** Every failure banner's pointer to the method and its agent prompt; callers add the indentation. */
    public const string DEFENCE_BEFORE_FIX_LINE = 'Defence Before Fix (DBF): https://defence-before-fix.github.io/ - agent prompt: https://defence-before-fix.github.io/defence-before-fix-project-prompt.md';

    public function __construct(
        private ToolLocatorInterface $tools,
        private RetryPromptInterface $prompt,
        private OutputInterface $output,
        private SignalledChildren $signalled = new SignalledChildren(),
    ) {
    }

    public function execute(string $name, ToolContext $context): ExecutionDto
    {
        $tool    = $this->tools->locate($name);
        $retried = false;
        while (true) {
            $result  = $tool->run($context);
            $crashes = $this->signalled->take();
            if ([] !== $crashes) {
                return $this->crashed($name, $retried, ...$crashes);
            }

            if ($result->isSuccess()) {
                return new ExecutionDto($result, $retried);
            }

            $this->failureBanner($name, $result->summary);
            if (ToolOutcomeEnum::Failed !== $result->outcome || !$this->prompt->shouldRetry($name)) {
                return new ExecutionDto($result, $retried);
            }

            $retried = true;
        }
    }

    /**
     * A child of the lane was killed by a signal, or exited 139 to relay a
     * crash below it, so the lane crashed whatever it made of the exit code:
     * its verdict rests on a tool that never finished.
     */
    private function crashed(string $name, bool $retried, SignalledChildDto ...$crashes): ExecutionDto
    {
        $result = ToolResultDto::crashed(\sprintf(
            '%s crashed: %s',
            $name,
            implode('; ', array_map(static fn (SignalledChildDto $crash): string => $crash->describe(), $crashes)),
        ));
        $this->failureBanner($name, $result->summary);

        return new ExecutionDto(
            $result,
            $retried,
            array_any($crashes, static fn (SignalledChildDto $crash): bool => $crash->isSegmentationFault()),
            childCrashed: true,
        );
    }

    private function failureBanner(string $name, string $summary): void
    {
        $this->output->writeln('');
        $this->output->writeln('    ==================================================');
        $this->output->writeln('');
        $this->output->writeln(\sprintf('        %s Failed...', $name));
        if ('' !== $summary) {
            $this->output->writeln('        ' . $summary);
        }

        $this->output->writeln('');
        $this->output->writeln('        ' . self::DEFENCE_BEFORE_FIX_LINE);
        $this->output->writeln('');
        $this->output->writeln('    ==================================================');
        $this->output->writeln('');
    }
}
