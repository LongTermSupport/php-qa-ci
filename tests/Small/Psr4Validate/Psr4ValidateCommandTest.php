<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Psr4Validate;

use LTS\PHPQA\Helper;
use LTS\PHPQA\Psr4Validate\Psr4ValidateCommand;
use LTS\PHPQA\Psr4Validator;
use LTS\PHPQA\Regex\RegexPattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * bin/psr4-validate took every argument as an ignore pattern, so `--help`
 * was compiled as one and failed with an anonymous PREG_INTERNAL_ERROR (#192).
 *
 * @internal
 */
#[CoversClass(Psr4ValidateCommand::class)]
#[UsesClass(Psr4Validator::class)]
#[UsesClass(RegexPattern::class)]
#[UsesClass(Helper::class)]
#[Small]
final class Psr4ValidateCommandTest extends TestCase
{
    private const string VALID_PROJECT = __DIR__ . '/../../assets/psr4/projectAllValid';

    private const string INVALID_PROJECT = __DIR__ . '/../../assets/psr4/projectInValid';

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function helpOptions(): iterable
    {
        yield 'long' => ['--help'];
        yield 'short' => ['-h'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('helpOptions')]
    public function theHelpOptionPrintsTheUsageAndValidatesNothing(string $option): void
    {
        $exitCode = $this->command(self::INVALID_PROJECT)->run('#/src/Legacy/#', $option);
        $output   = $this->output->fetch();

        self::assertSame(Psr4ValidateCommand::EXIT_PASSED, $exitCode);
        self::assertStringStartsWith('Usage: psr4-validate [<ignore-pattern> ...]', $output);
        self::assertStringNotContainsString('Errors found', $output);
    }

    #[Test]
    public function anArgumentThatIsNotAValidPatternFailsNamingIt(): void
    {
        $exitCode = $this->command(self::VALID_PROJECT)->run('#/src/Legacy/#', '--verbose');
        $output   = $this->output->fetch();

        self::assertSame(Psr4ValidateCommand::EXIT_FAILED, $exitCode);
        self::assertStringContainsString("ignore pattern \"--verbose\" is not a valid regular expression: preg_match(): Unknown modifier 'v'", $output);
        self::assertStringContainsString('psr4-validate --help', $output);
        self::assertStringNotContainsString('PREG_INTERNAL_ERROR', $output);
    }

    #[Test]
    public function aValidProjectReportsNoErrors(): void
    {
        $exitCode = $this->command(self::VALID_PROJECT)->run();

        self::assertSame(Psr4ValidateCommand::EXIT_PASSED, $exitCode);
        self::assertSame("\nNo errors found\n", $this->output->fetch());
    }

    #[Test]
    public function anInvalidProjectReportsItsErrorsAndFails(): void
    {
        $exitCode = $this->command(self::INVALID_PROJECT)->run('%IgnoredStuff%');
        $output   = $this->output->fetch();

        self::assertSame(Psr4ValidateCommand::EXIT_FAILED, $exitCode);
        self::assertStringContainsString("\nErrors found:\n", $output);
        self::assertStringContainsString('In\Valid\Nested\Deep', $output);
        self::assertStringEndsWith("\nErrors validating PSR4\n", $output);
    }

    private function command(string $project): Psr4ValidateCommand
    {
        $root = \Safe\realpath($project);

        return new Psr4ValidateCommand($root, Helper::getComposerJsonDecoded($root . '/composer.json'), $this->output);
    }
}
