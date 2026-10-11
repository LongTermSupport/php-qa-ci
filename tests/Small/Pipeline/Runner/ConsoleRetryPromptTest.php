<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Runner\ConsoleRetryPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(ConsoleRetryPrompt::class)]
#[Small]
final class ConsoleRetryPromptTest extends TestCase
{
    private const string LABEL = 'lint';

    private const string QUESTION = "\n"
        . "        lint Failed... would you like to try again? (y/n)\n"
        . "        (note: if you change config files, you might have to run from the top for it to take effect)\n"
        . "\n";

    private const string ABORTING = "Aborting...\n";

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function yesRetriesAndSaysSo(): void
    {
        self::assertTrue($this->prompt("y\n")->shouldRetry(self::LABEL));
        self::assertSame(self::QUESTION . "Trying again, good luck!\n", $this->output->fetch());
    }

    #[Test]
    public function noAbortsAndSaysSo(): void
    {
        self::assertFalse($this->prompt("n\n")->shouldRetry(self::LABEL));
        self::assertSame(self::QUESTION . self::ABORTING, $this->output->fetch());
    }

    #[Test]
    public function endOfInputAbortsAndSaysSo(): void
    {
        self::assertFalse($this->prompt('')->shouldRetry(self::LABEL));
        self::assertSame(self::QUESTION . self::ABORTING, $this->output->fetch());
    }

    /** The answer is read case-insensitively with surrounding whitespace ignored. */
    #[Test]
    public function anUppercaseAnswerPaddedWithSpacesCounts(): void
    {
        self::assertFalse($this->prompt("  N  \n")->shouldRetry(self::LABEL));
        self::assertTrue($this->prompt("\tY \n")->shouldRetry(self::LABEL));
    }

    /** A no ends the prompt: nothing after it is read as a further answer. */
    #[Test]
    public function aNoIsFinalEvenWithAYesQueuedBehindIt(): void
    {
        self::assertFalse($this->prompt("n\ny\n")->shouldRetry(self::LABEL));
        self::assertSame(self::QUESTION . self::ABORTING, $this->output->fetch());
    }

    #[Test]
    public function anInvalidAnswerIsNamedAndTheQuestionWaitsForAnother(): void
    {
        self::assertTrue($this->prompt("Maybe\ny\n")->shouldRetry(self::LABEL));
        self::assertSame(
            self::QUESTION . "invalid choice: maybe - should be y or n\nTrying again, good luck!\n",
            $this->output->fetch(),
        );
    }

    /** One prompt answers each failure of a run in turn, reading on from where the last answer ended. */
    #[Test]
    public function successiveQuestionsReadSuccessiveAnswersFromTheSameInput(): void
    {
        $prompt = $this->prompt("maybe\ny\nn\n");

        self::assertTrue($prompt->shouldRetry(self::LABEL));
        self::assertFalse($prompt->shouldRetry(self::LABEL));
        self::assertFalse($prompt->shouldRetry(self::LABEL), 'end of input is a no');
    }

    private function prompt(string $input): ConsoleRetryPrompt
    {
        $stdin = \Safe\fopen('php://memory', 'r+');
        \Safe\fwrite($stdin, $input);
        \Safe\rewind($stdin);

        return new ConsoleRetryPrompt($this->output, $stdin);
    }
}
