<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhpstanTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpstanTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[Small]
final class PhpstanToolTest extends TestCase
{
    private const string NO_PROGRESS = '--no-progress';

    private const string VAR_QA_PREFIX = 'var/qa/';

    private const string PHP_VERSION = '8.5.0';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        // PhpInvoker asks the binary for its version before every run and then looks
        // for the no-Xdebug ini of that version; pre-seeding the ini keeps the fake
        // runner's queue to "version, then the tool" per invocation.
        $this->factory->project->write('var/qa/phpqa-no-xdebug.' . self::PHP_VERSION . '.ini', "memory_limit=-1\n");
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCleanRunWritesTheWrapperNeonRunsThePharAndArchivesTheLog(): void
    {
        $this->queueVersion()->willSucceed("[OK] No errors\n");
        $config = $this->factory->builder(ci: true)->build();

        $result  = new PhpstanTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('PHPStan: limiting to 2 parallel processes (50% of cores)', $printed);

        $logDir  = $this->factory->project->path . '/var/qa/' . PhpstanTool::LOG_DIR;
        $wrapper = $logDir . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertSame(
            "includes:\n    - " . \dirname(__DIR__, 4) . "/configDefaults/generic/phpstan.neon\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: 2\n",
            \Safe\file_get_contents($wrapper),
        );

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(
            ['analyse', ...$config->pathsToCheck, '-c', $wrapper, self::NO_PROGRESS],
            $this->toolArgs($spec),
        );
        self::assertSame(\dirname(__DIR__, 4) . '/vendor-phar/phpstan.phar', $this->script($spec));
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertTrue($spec->streamOutput);

        self::assertSame("[OK] No errors\n", \Safe\file_get_contents($logDir . '/' . PhpstanTool::LOG_FILE));
        $archived = array_filter($this->factory->project->files(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR), static fn (string $f): bool => 1 === \Safe\preg_match('/^phpstan\.\d{8}-\d{6}\.log$/', $f));
        self::assertCount(1, $archived, 'the text log is archived with a timestamp');
        self::assertStringContainsString('Full test suite run', $printed);
        self::assertSame('', $this->factory->stdout->fetch(), 'text mode never touches the real stdout');
    }

    #[Test]
    public function theProjectOverrideNeonIsIncludedWhenPresent(): void
    {
        $override = $this->factory->project->write('qaConfig/phpstan.neon', "parameters:\n    level: max\n");
        $this->queueVersion()->willSucceed();

        new PhpstanTool()->run($this->factory->context());

        self::assertStringContainsString('    - ' . $override . "\n", $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON));
    }

    #[Test]
    public function anInteractiveRunKeepsTheProgressBar(): void
    {
        $this->queueVersion()->willSucceed();

        new PhpstanTool()->run($this->factory->context($this->factory->builder(ci: false)->build()));

        self::assertNotContains(self::NO_PROGRESS, $this->toolArgs($this->factory->processes->lastSpec()));
    }

    #[Test]
    public function errorsFoundFailWithTheIdentifierAndNoTautologyNote(): void
    {
        $this->queueVersion()->willFail(1, " 12  Method foo() has no return type specified.\n");

        $result  = new PhpstanTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('possible tautology', $printed);
        self::assertCount(2, $this->factory->processes->specs, 'no debug re-run for a plain failure');
    }

    #[Test]
    public function aTautologyIdentifierInTheOutputPrintsTheNote(): void
    {
        $this->queueVersion()->willFail(1, "Call to method assertTrue() with true will always evaluate to true.\n  🪪 method.alreadyNarrowedType\n");

        $result  = new PhpstanTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('NOTE — possible tautology from stronger types', $printed);
        self::assertStringContainsString('DELETING the now-redundant assertion', $printed);
        self::assertStringContainsString('Do NOT silence it with treatPhpDocTypesAsCertain:false', $printed);
        self::assertStringContainsString(PhpstanTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aCrashIsReRunWithDebugAndReportedAsCrashed(): void
    {
        $this->queueVersion()->willFail(255, "PHP Fatal error: boom\n");
        $this->queueVersion()->willFail(255, "debug output\n");
        $config = $this->factory->builder(ci: true)->build();

        $result  = new PhpstanTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('PHPStan Crashed!!....', $printed);
        self::assertStringContainsString('Where ever it stops is probably a fatal PHP error', $printed);
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);

        $wrapper = $this->factory->project->path . '/var/qa/' . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertCount(4, $this->factory->processes->specs);
        self::assertSame(
            ['analyse', ...$config->pathsToCheck, '-c', $wrapper, '--debug', '-v'],
            $this->toolArgs($this->factory->processes->lastSpec()),
            'the debug re-run drops --no-progress and adds --debug -v',
        );
    }

    #[Test]
    public function jsonModeWritesOnlyTheProcessStdoutSoStderrNoiseNeverCorruptsTheReport(): void
    {
        $json  = '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}';
        $noise = "PHP Warning:  Module \"xml\" is already loaded in Unknown on line 0\n";
        $this->queueVersion()->willReturn(new ProcessResultDto(0, $noise . $json, $json));
        $config = $this->factory->builder(jsonOutput: true, specifiedPath: 'src')->build();

        $result = new PhpstanTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($json, $this->factory->stdout->fetch());
    }

    #[Test]
    public function jsonModeWritesTheReportToStdoutOnlyAndNeverRetries(): void
    {
        $json = '{"totals":{"errors":0,"file_errors":1},"files":{},"errors":[]}';
        $this->queueVersion()->willFail(1, $json);
        $config = $this->factory->builder(ci: false, jsonOutput: true, specifiedPath: 'src')->build();

        $result = new PhpstanTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame($json, $this->factory->stdout->fetch());
        $printed = $this->factory->output->fetch();
        self::assertStringNotContainsString('"totals"', $printed, 'the JSON never reaches the decoration output');
        self::assertStringContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertStringContainsString('Path-specific run', $printed);

        $spec = $this->factory->processes->lastSpec();
        self::assertFalse($spec->streamOutput);
        $args = $this->toolArgs($spec);
        self::assertContains('--error-format=json', $args);
        self::assertContains(self::NO_PROGRESS, $args, 'json mode always suppresses progress, even interactively');
        self::assertCount(2, $this->factory->processes->specs, 'no re-run of any kind in json mode');

        $logDir = self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR;
        self::assertSame($json, $this->factory->project->read($logDir . '/' . PhpstanTool::JSON_FILE));
        $archived = array_filter($this->factory->project->files($logDir), static fn (string $f): bool => 1 === \Safe\preg_match('/^phpstan\..*_src\.\d{8}-\d{6}\.json$/', $f));
        self::assertCount(1, $archived, 'the json report is archived under the path-specific pattern');
    }

    #[Test]
    public function jsonModeCleanRunPasses(): void
    {
        $this->queueVersion()->willSucceed('{"totals":{"errors":0,"file_errors":0}}');

        $result = new PhpstanTool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('{"totals":{"errors":0,"file_errors":0}}', $this->factory->stdout->fetch());
    }

    #[Test]
    public function jsonModeCrashIsReportedWithoutADebugReRun(): void
    {
        $this->queueVersion()->willFail(255, 'Fatal');

        $result = new PhpstanTool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('PHPStan crashed (exit code: 255)', $this->factory->output->fetch());
        self::assertCount(2, $this->factory->processes->specs);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpstanTool();

        self::assertSame('phpstan', $tool->name());
        self::assertSame('phpqaci.phpstan', $tool->identifier());
        self::assertSame(PhpstanTool::IDENTIFIER, $tool->identifier());
    }

    private function queueVersion(): FakeProcessRunner
    {
        return $this->factory->processes->willSucceed(self::PHP_VERSION);
    }

    /** @return list<string> everything after the "--" separator */
    private function toolArgs(ProcessSpecDto $spec): array
    {
        $separator = array_search('--', $spec->command, true);
        self::assertIsInt($separator);

        return \array_slice($spec->command, $separator + 1);
    }

    private function script(ProcessSpecDto $spec): string
    {
        $flag = array_search('-f', $spec->command, true);
        self::assertIsInt($flag);

        return $spec->command[$flag + 1];
    }
}
