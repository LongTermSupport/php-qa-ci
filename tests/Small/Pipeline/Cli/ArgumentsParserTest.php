<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Cli;

use LTS\PHPQA\Pipeline\Cli\ArgumentsParser;
use LTS\PHPQA\Pipeline\Cli\Dto\RunRequestDto;
use LTS\PHPQA\Pipeline\Cli\Exception\UsageException;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ArgumentsParser::class)]
#[CoversClass(RunRequestDto::class)]
#[CoversClass(UsageException::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ToolRegistry::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Tool\PhaseEnum::class)]
#[Small]
final class ArgumentsParserTest extends TestCase
{
    private const string PHPSTAN = 'phpstan';

    private const string STAN = 'stan';

    private const string SRC_DOMAIN = 'src/Domain';

    private const string SRC = 'src';

    private const string JSON_OPTION = '--json';

    private const string AGENT_OPTION = '--agent-mode';

    private const string BIN_DIR = 'vendor/bin';

    private const string UNIT = 'unit';

    private const string ATTACHED_STAN = '-tstan';

    private const string ATTACHED_SRC = '-psrc';

    private const string ATTACHED_TESTS = '-ptests';

    private const string EXTRA = 'extra';

    private ArgumentsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ArgumentsParser(ToolRegistry::shipped(), self::BIN_DIR);
    }

    #[Test]
    public function noArgumentsMeansTheFullPipeline(): void
    {
        $request = $this->parser->parse();

        self::assertNull($request->tool);
        self::assertNull($request->path);
    }

    #[Test]
    public function aToolTokenResolvesToItsCanonicalName(): void
    {
        self::assertSame(self::PHPSTAN, $this->parser->parse('-t', self::STAN)->tool);
        self::assertSame(self::PHPSTAN, $this->parser->parse(self::ATTACHED_STAN)->tool);
        self::assertSame('allLintingTools', $this->parser->parse('-t', 'allLints')->tool);
    }

    #[Test]
    public function aPseudoToolRecordsTheTokenThatWasTyped(): void
    {
        $request = $this->parser->parse('-t', 'uniterate');

        self::assertSame('phpunit', $request->tool);
        self::assertSame('uniterate', $request->selectedToken);
        self::assertNull($this->parser->parse('-t', self::UNIT)->selectedToken);
    }

    #[Test]
    public function pathsComeFromDashPOrASingleBareArgument(): void
    {
        self::assertSame(self::SRC_DOMAIN, $this->parser->parse('-t', self::STAN, '-p', self::SRC_DOMAIN)->path);
        self::assertSame(self::SRC_DOMAIN, $this->parser->parse('-t', self::STAN, self::SRC_DOMAIN)->path);
        self::assertSame(self::SRC, $this->parser->parse(self::ATTACHED_SRC)->path);
    }

    #[Test]
    public function jsonIsAcceptedAnywhereForPhpstan(): void
    {
        self::assertSame(self::PHPSTAN, $this->parser->parse(self::JSON_OPTION, '-t', self::STAN)->tool);
        self::assertSame(self::PHPSTAN, $this->parser->parse('-t', self::PHPSTAN, self::JSON_OPTION)->tool);
    }

    #[Test]
    public function agentModeIsAcceptedAnywhereForPhpstan(): void
    {
        self::assertTrue($this->parser->parse(self::AGENT_OPTION, '-t', self::STAN)->agentMode);
        self::assertTrue($this->parser->parse('-t', self::PHPSTAN, '-p', self::SRC, self::AGENT_OPTION)->agentMode);
        self::assertSame(self::SRC, $this->parser->parse(self::AGENT_OPTION, '-t', self::STAN, '-p', self::SRC)->path);
    }

    #[Test]
    public function agentModeIsOffUnlessAskedFor(): void
    {
        self::assertFalse($this->parser->parse('-t', self::STAN)->agentMode);
        self::assertFalse($this->parser->parse()->agentMode);
    }

    #[Test]
    public function theEnvironmentTurnsAgentModeOnWithoutTouchingTheCommandLine(): void
    {
        $fromEnv = new ArgumentsParser(ToolRegistry::shipped(), self::BIN_DIR, agentModeFromEnvironment: true);

        $request = $fromEnv->parse('-t', self::STAN, '-p', self::SRC);

        self::assertTrue($request->agentMode, 'a hook sets the variable rather than rewriting the command it runs');
        self::assertSame(self::PHPSTAN, $request->tool);
    }

    #[Test]
    public function agentModeNeedsASingleToolBecauseAWholePipelineHasNoTerseReport(): void
    {
        $this->assertUsage('--agent-mode requires a single tool (-t)', false, self::AGENT_OPTION);
    }

    #[Test]
    public function agentModeRefusesAToolThatCannotProduceAReportRatherThanSilentlyPrintingItsUsualOutput(): void
    {
        $this->assertUsage("--agent-mode is not supported for 'phpunit'", false, self::AGENT_OPTION, '-t', self::UNIT);
        $this->assertUsage("--agent-mode is not supported for 'phpLint'", false, self::AGENT_OPTION, '-t', 'lint');
    }

    #[Test]
    public function agentModeRefusesAPhaseRunner(): void
    {
        $this->assertUsage("--agent-mode is not supported for 'allCodingStandardsTools'", false, self::AGENT_OPTION, '-t', 'allCS');
    }

    #[Test]
    public function theRefusalNamesTheToolsThatDoSupportAgentMode(): void
    {
        $exception = null;

        try {
            $this->parser->parse(self::AGENT_OPTION, '-t', self::UNIT);
        } catch (UsageException $usageException) {
            $exception = $usageException;
        }

        self::assertInstanceOf(UsageException::class, $exception);
        self::assertStringContainsString(self::PHPSTAN, $exception->getMessage());
    }

    #[Test]
    public function theEnvironmentVariableIsSubjectToTheSameRefusalAsTheFlag(): void
    {
        $fromEnv = new ArgumentsParser(ToolRegistry::shipped(), self::BIN_DIR, agentModeFromEnvironment: true);

        $this->expectException(UsageException::class);
        $this->expectExceptionMessageIsOrContains("--agent-mode is not supported for 'phpunit'");

        $fromEnv->parse('-t', self::UNIT);
    }

    #[Test]
    public function helpRaisesAUsageErrorThatShowsTheUsage(): void
    {
        $this->assertUsage('', true, '-h');
    }

    #[Test]
    public function helpCarriesNoErrorMessageOfItsOwn(): void
    {
        try {
            $this->parser->parse('-h');
            self::fail('expected a UsageException for -h');
        } catch (UsageException $usageException) {
            self::assertSame('', $usageException->getMessage(), '-h is a request for the usage, not an option missing its value');
            self::assertTrue($usageException->showUsage);
        }
    }

    #[Test]
    public function argumentsUnpackedFromAKeyedArrayAreReadInOrder(): void
    {
        $request = $this->parser->parse(...['tool' => '-t', 'name' => self::STAN, 'path' => self::ATTACHED_SRC]);

        self::assertSame(self::PHPSTAN, $request->tool);
        self::assertSame(self::SRC, $request->path);
    }

    #[Test]
    public function theAgentModeRefusalListsEachSupportingToolOnItsOwnIndentedLine(): void
    {
        $listing = implode("\n", array_map(static fn (string $tool): string => '  ' . $tool, ToolRegistry::shipped()->agentModeTools()));

        try {
            $this->parser->parse(self::AGENT_OPTION);
            self::fail('expected a UsageException for --agent-mode without -t');
        } catch (UsageException $usageException) {
            self::assertSame(
                "\nERROR: --agent-mode requires a single tool (-t)\n  Example: vendor/bin/qa --agent-mode -t phpstan -p src/Kernel.php\n"
                . "\nTools with --agent-mode support:\n" . $listing . "\n",
                $usageException->getMessage(),
            );
        }
    }

    #[Test]
    public function anUnknownToolShowsTheUsage(): void
    {
        $this->assertUsage('Invalid tool: nope', true, '-t', 'nope');
        $this->assertUsage('Invalid tool: psr4Validate', true, '-t', 'psr4Validate');
    }

    #[Test]
    public function aMissingOptionValueShowsTheUsage(): void
    {
        $this->assertUsage('Option -t requires an argument', true, '-t');
        $this->assertUsage('Option -p requires an argument', true, '-p');
    }

    #[Test]
    public function unknownFlagsAndExtraArgumentsAreRefused(): void
    {
        $this->assertUsage('Unsupported argument: --help', false, '-t', self::STAN, '--help');
        $this->assertUsage('Unsupported argument: -x', false, '-x');
        $this->assertUsage('Multiple paths not supported: src tests', false, self::SRC, 'tests');
        $this->assertUsage('Unsupported argument: extra', false, '-p', self::SRC, self::EXTRA);
    }

    #[Test]
    public function aRepeatedPathOptionIsRefusedRatherThanDroppingTheFirst(): void
    {
        $bothPaths = 'Multiple paths not supported: -p src -p tests';
        foreach ([['-p', self::SRC, '-p', 'tests'], [self::ATTACHED_SRC, self::ATTACHED_TESTS], ['-p', self::SRC, self::ATTACHED_TESTS]] as $paths) {
            $this->assertUsage($bothPaths, false, '-t', self::STAN, ...$paths);
        }

        $this->assertUsage('Multiple paths not supported: -p src -p src', false, self::ATTACHED_SRC, '-p', self::SRC);
        $this->assertUsage('Specify a single path: vendor/bin/qa -t toolname -p path/to/check', false, '-p', self::SRC, self::ATTACHED_TESTS);
    }

    #[Test]
    public function aRepeatedToolOptionIsRefusedRatherThanDroppingTheFirst(): void
    {
        $bothTools = 'Multiple tools not supported: -t stan -t rector';
        foreach ([['-t', self::STAN, '-t', 'rector'], [self::ATTACHED_STAN, '-trector'], [self::ATTACHED_STAN, '-t', 'rector']] as $tools) {
            $this->assertUsage($bothTools, false, ...$tools);
        }

        $this->assertUsage('Multiple tools not supported: -t stan -t stan', false, '-t', self::STAN, self::ATTACHED_STAN);
        $this->assertUsage('Specify a single tool: vendor/bin/qa -t toolname', false, '-t', self::STAN, '-tphpunit');
    }

    #[Test]
    public function aPathOptionTogetherWithABarePathIsRefused(): void
    {
        foreach ([['-p', self::SRC, self::EXTRA], [self::EXTRA, '-p', self::SRC], [self::ATTACHED_SRC, self::EXTRA]] as $arguments) {
            $this->assertUsage('Unsupported argument: extra', false, '-t', self::STAN, ...$arguments);
        }
    }

    #[Test]
    public function aPathWithANonPathToolIsRefusedAndListsThePathSupportingTokens(): void
    {
        try {
            $this->parser->parse('-t', 'cr', '-p', self::SRC);
            self::fail('expected UsageException');
        } catch (UsageException $usageException) {
            self::assertStringContainsString("Tool 'cr' does not support path-specific execution", $usageException->getMessage());
            self::assertStringContainsString('  stan', $usageException->getMessage());
            self::assertStringContainsString('vendor/bin/qa -t cr', $usageException->getMessage());
        }
    }

    #[Test]
    public function jsonNeedsAToolThatSupportsIt(): void
    {
        $this->assertUsage('--json requires a single tool (-t)', false, self::JSON_OPTION);
        $this->assertUsage("--json is not yet supported for 'phpunit'", false, self::JSON_OPTION, '-t', self::UNIT);
    }

    #[Test]
    public function theUsageTextListsEveryToolFromTheRegistry(): void
    {
        $usage = $this->parser->usage();

        self::assertStringStartsWith("Usage:\nvendor/bin/qa [-t tool to run ] [ -p path to scan ] [ --json ] [ --agent-mode ]", $usage);
        self::assertStringContainsString('PHPQACI_AGENT_MODE=1', $usage);
        self::assertStringContainsString(\sprintf('     %-26s %s', 'allCS', 'all coding standards tools'), $usage);
        self::assertStringContainsString('vp|versionPins', $usage);
        self::assertStringEndsWith("\n", $usage, 'the usage is printed as-is, so it must end its own last line');
    }

    private function assertUsage(string $expectedMessage, bool $showUsage, string ...$argv): void
    {
        try {
            $this->parser->parse(...$argv);
        } catch (UsageException $usageException) {
            self::assertStringContainsString($expectedMessage, $usageException->getMessage());
            self::assertSame($showUsage, $usageException->showUsage);

            return;
        }

        self::fail('expected a UsageException for ' . implode(' ', $argv));
    }
}
