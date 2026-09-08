<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive runs: ask "try again? (y/n)" on the terminal and loop until a
 * usable answer arrives. End of input counts as no.
 *
 * @internal
 */
final readonly class ConsoleRetryPrompt implements RetryPromptInterface
{
    /** @param resource $stdin */
    public function __construct(
        private OutputInterface $output,
        private mixed $stdin,
    ) {
    }

    public function shouldRetry(string $label): bool
    {
        $this->output->writeln('');
        $this->output->writeln(\sprintf('        %s Failed... would you like to try again? (y/n)', $label));
        $this->output->writeln('        (note: if you change config files, you might have to run from the top for it to take effect)');
        $this->output->writeln('');

        while (true) {
            $line = fgets($this->stdin);
            if (false === $line) {
                $this->output->writeln('Aborting...');

                return false;
            }

            $answer = strtolower(trim($line));
            if ('n' === $answer) {
                $this->output->writeln('Aborting...');

                return false;
            }

            if ('y' === $answer) {
                $this->output->writeln('Trying again, good luck!');

                return true;
            }

            $this->output->writeln(\sprintf('invalid choice: %s - should be y or n', $answer));
        }
    }
}
