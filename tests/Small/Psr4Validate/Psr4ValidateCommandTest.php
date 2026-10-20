<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Psr4Validate;

use LTS\PHPQA\Helper;
use LTS\PHPQA\Psr4Validate\Psr4ValidateCommand;
use LTS\PHPQA\Psr4Validator;
use LTS\PHPQA\Regex\RegexPattern;
use LTS\PHPQA\Tests\Support\TempDir;
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
#[UsesClass(\LTS\PHPQA\Filesystem\UnresolvablePath::class)]
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function helpOptions(): iterable
    {
        yield 'long' => ['--help'];
        yield 'short' => ['-h'];
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
        self::assertStringContainsString("'actualNamespace' => 'Totally'", $output);
        self::assertStringEndsWith("\nErrors validating PSR4\n", $output);
    }

    #[Test]
    public function aDanglingSymlinkIsNamedAsSkippedAndTheRestStillValidates(): void
    {
        $project = TempDir::create('phpqa-psr4-command-dangling');

        try {
            $project->write('composer.json', '{"autoload": {"psr-4": {"Probe\\\": "src/"}}}');
            $project->write('src/Real.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Real\n{\n}\n");
            \Safe\symlink('missing-target.php', $project->path . '/src/Gone.php');

            $exitCode = $this->command($project->path)->run();

            self::assertSame(Psr4ValidateCommand::EXIT_PASSED, $exitCode);
            self::assertSame(
                "\npsr4-validate skipped src/Gone.php: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked\n\nNo errors found\n",
                $this->output->fetch(),
            );
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function aProjectWithoutAComposerJsonFailsWithTheReason(): void
    {
        $root     = \Safe\realpath(__DIR__);
        $exitCode = $this->command($root)->run();

        self::assertSame(Psr4ValidateCommand::EXIT_FAILED, $exitCode);
        self::assertSame("\nNo composer.json in " . $root . "; run psr4-validate from the project root.\n", $this->output->fetch());
    }

    private function command(string $project): Psr4ValidateCommand
    {
        $root = \Safe\realpath($project);

        return new Psr4ValidateCommand(static fn (): string => $root, $this->output);
    }
}
