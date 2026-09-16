<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Agent\FileReportWriter;
use LTS\PHPQA\Pipeline\Lane\PhpstanTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpstanTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\AgentStatusEnum::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\ParsedReportDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException::class)]
#[UsesClass(FileReportWriter::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\PhpstanJsonParser::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\TerseReporter::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
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

    private const string ANALYSE = 'analyse';

    private const string SRC = 'src';

    private const string KERNEL = 'src/Kernel.php';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCleanRunWritesTheWrapperNeonRunsThePharAndArchivesTheLog(): void
    {
        $this->factory->processes->willSucceed("[OK] No errors\n");
        $config = $this->factory->builder(ci: true)->build();

        $result  = new PhpstanTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('PHPStan: limiting to 2 parallel processes (50% of cores)', $printed);

        $logDir  = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR;
        $wrapper = $logDir . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertSame(
            "includes:\n    - " . \dirname(__DIR__, 4) . "/configDefaults/generic/phpstan.neon\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: 2\n",
            \Safe\file_get_contents($wrapper),
        );

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $wrapper, self::NO_PROGRESS],
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
        $this->factory->processes->willSucceed();

        new PhpstanTool()->run($this->factory->context());

        self::assertStringContainsString('    - ' . $override . "\n", $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON));
    }

    #[Test]
    public function noTypeCoverageBlockIsWrittenWhenNoFloorIsSet(): void
    {
        $this->factory->processes->willSucceed();

        new PhpstanTool()->run($this->factory->context());

        self::assertStringNotContainsString(
            'type_coverage',
            $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON),
            'the extension must do nothing until a project opts in',
        );
    }

    #[Test]
    public function onlyTheTypeCoverageFloorsActuallySetAreWrittenToTheWrapperNeon(): void
    {
        $this->factory->processes->willSucceed();
        $config = $this->factory->builder()->withTypeCoverageFloors(returnType: 50, declare: 100)->build();

        new PhpstanTool()->run($this->factory->context($config));

        $wrapper = $this->factory->project->read(self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON);
        self::assertStringContainsString("    type_coverage:\n        return_type: 50\n        declare: 100\n", $wrapper);
        self::assertStringNotContainsString('param_type', $wrapper, 'an unset floor must not be written as zero');
        self::assertStringNotContainsString('property_type', $wrapper);
        self::assertStringNotContainsString('constant_type', $wrapper);
    }

    #[Test]
    public function withTypeCoverageOnTheWholeProjectThePathsMoveIntoTheConfigAndOffTheCommandLine(): void
    {
        $this->factory->processes->willSucceed();
        $config = $this->factory->builder()->withTypeCoverageFloors(returnType: 50)->build();

        new PhpstanTool()->run($this->factory->context($config));

        $wrapper = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertSame(
            [self::ANALYSE, '-c', $wrapper, self::NO_PROGRESS],
            $this->toolArgs($this->factory->processes->lastSpec()),
            'type-coverage reports nothing when the analysed paths differ from the configured ones',
        );
        foreach ($config->pathsToCheck as $path) {
            self::assertStringContainsString('        - ' . $path . "\n", \Safe\file_get_contents($wrapper));
        }
    }

    #[Test]
    public function aSinglePathRunKeepsTheCommandLineFormEvenWithTypeCoverageOn(): void
    {
        $this->factory->processes->willSucceed();
        $config = $this->factory->builder(specifiedPath: self::SRC)->withTypeCoverageFloors(returnType: 50)->build();

        new PhpstanTool()->run($this->factory->context($config));

        $wrapper = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $wrapper, self::NO_PROGRESS],
            $this->toolArgs($this->factory->processes->lastSpec()),
        );
        self::assertStringNotContainsString('    paths:', \Safe\file_get_contents($wrapper), 'a subset must not masquerade as the whole project');
    }

    #[Test]
    public function anInteractiveRunKeepsTheProgressBar(): void
    {
        $this->factory->processes->willSucceed();

        new PhpstanTool()->run($this->factory->context($this->factory->builder(ci: false)->build()));

        self::assertNotContains(self::NO_PROGRESS, $this->toolArgs($this->factory->processes->lastSpec()));
    }

    #[Test]
    public function errorsFoundFailWithTheIdentifierAndNoTautologyNote(): void
    {
        $this->factory->processes->willFail(1, " 12  Method foo() has no return type specified.\n");

        $result  = new PhpstanTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(PhpstanTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('possible tautology', $printed);
        self::assertCount(1, $this->factory->processes->specs, 'no debug re-run for a plain failure');
    }

    #[Test]
    public function aTautologyIdentifierInTheOutputPrintsTheNote(): void
    {
        $this->factory->processes->willFail(1, "Call to method assertTrue() with true will always evaluate to true.\n  🪪 method.alreadyNarrowedType\n");

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
        $this->factory->processes->willFail(255, "PHP Fatal error: boom\n");
        $this->factory->processes->willFail(255, "debug output\n");

        $config = $this->factory->builder(ci: true)->build();

        $result  = new PhpstanTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('PHPStan Crashed!!....', $printed);
        self::assertStringContainsString('Where ever it stops is probably a fatal PHP error', $printed);
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);

        $wrapper = $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR . '/' . PhpstanTool::WRAPPER_NEON;
        self::assertCount(2, $this->factory->processes->specs);
        self::assertSame(
            [self::ANALYSE, ...$config->pathsToCheck, '-c', $wrapper, '--debug', '-v'],
            $this->toolArgs($this->factory->processes->lastSpec()),
            'the debug re-run drops --no-progress and adds --debug -v',
        );
    }

    #[Test]
    public function jsonModeWritesOnlyTheProcessStdoutSoStderrNoiseNeverCorruptsTheReport(): void
    {
        $json  = '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}';
        $noise = "PHP Warning:  Module \"xml\" is already loaded in Unknown on line 0\n";
        $this->factory->processes->willReturn(new ProcessResultDto(0, $noise . $json, $json));
        $config = $this->factory->builder(jsonOutput: true, specifiedPath: self::SRC)->build();

        $result = new PhpstanTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($json, $this->factory->stdout->fetch());
    }

    #[Test]
    public function jsonModeWritesTheReportToStdoutOnlyAndNeverRetries(): void
    {
        $json = '{"totals":{"errors":0,"file_errors":1},"files":{},"errors":[]}';
        $this->factory->processes->willFail(1, $json);
        $config = $this->factory->builder(ci: false, jsonOutput: true, specifiedPath: self::SRC)->build();

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
        self::assertCount(1, $this->factory->processes->specs, 'no re-run of any kind in json mode');

        $logDir = self::VAR_QA_PREFIX . PhpstanTool::LOG_DIR;
        self::assertSame($json, $this->factory->project->read($logDir . '/' . PhpstanTool::JSON_FILE));
        $archived = array_filter($this->factory->project->files($logDir), static fn (string $f): bool => 1 === \Safe\preg_match('/^phpstan\..*_src\.\d{8}-\d{6}\.json$/', $f));
        self::assertCount(1, $archived, 'the json report is archived under the path-specific pattern');
    }

    #[Test]
    public function jsonModeCleanRunPasses(): void
    {
        $this->factory->processes->willSucceed('{"totals":{"errors":0,"file_errors":0}}');

        $result = new PhpstanTool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('{"totals":{"errors":0,"file_errors":0}}', $this->factory->stdout->fetch());
    }

    #[Test]
    public function jsonModeCrashIsReportedWithoutADebugReRun(): void
    {
        $this->factory->processes->willFail(255, 'Fatal');

        $result = new PhpstanTool()->run($this->factory->context($this->factory->builder(jsonOutput: true)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('PHPStan crashed (exit code: 255)', $this->factory->output->fetch());
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function agentModeAsksPhpstanForJsonAndKeepsStdoutToThreeLines(): void
    {
        $file = $this->factory->project->write(self::KERNEL, "<?php\n");
        $this->factory->processes->willFail(1, $this->reportFor($file, 2));
        $config = $this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build();

        $result = new PhpstanTool()->run($this->factory->context($config));
        $lines  = $this->stdoutLines();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(3, $lines);
        self::assertSame('PHPSTAN AGENT MODE: 2 errors in 1 file', $lines[0]);
        self::assertSame('REPORT: ' . $this->reportPath(self::KERNEL), $lines[1]);
        self::assertStringContainsString('ACTION REQUIRED', $lines[2]);
        self::assertContains('--error-format=json', $this->toolArgs($this->factory->processes->lastSpec()));
    }

    #[Test]
    public function agentModeWritesThePerFileReportAtThePathThatMirrorsTheSourceFile(): void
    {
        $file = $this->factory->project->write(self::KERNEL, "<?php\n");
        $this->factory->processes->willFail(1, $this->reportFor($file, 1));

        new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        $report = $this->decode($this->reportPath(self::KERNEL));
        self::assertSame('phpstan', $report['tool']);
        self::assertSame(self::KERNEL, $report['path']);
        self::assertSame('errors', $report['status']);
        self::assertSame(1, $report['error_count']);
        self::assertSame(1, $report['exit_code']);
        self::assertSame(11, $report['errors'][0]['line']);
        self::assertSame('missingType.return', $report['errors'][0]['identifier']);
        self::assertStringEndsWith(PhpstanTool::JSON_FILE, $report['log_path']);
    }

    #[Test]
    public function aCleanAgentRunWritesZeroForTheAnalysedFileSoAFixedErrorCannotStayRed(): void
    {
        $file  = $this->factory->project->write(self::KERNEL, "<?php\n");
        $clean = '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}';

        $this->factory->processes->willFail(1, $this->reportFor($file, 3));
        new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));
        self::assertSame(3, $this->decode($this->reportPath(self::KERNEL))['error_count']);
        $this->factory->stdout->fetch();

        $this->factory->processes->willSucceed($clean);
        $result = new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $report = $this->decode($this->reportPath(self::KERNEL));
        self::assertSame(0, $report['error_count']);
        self::assertSame('clean', $report['status']);
        self::assertSame([], $report['errors']);
        self::assertSame('PHPSTAN AGENT MODE: 0 errors in 1 file', $this->stdoutLines()[0]);
    }

    #[Test]
    public function aWholeProjectAgentRunWritesOneReportPerFailingFileAndPointsAtTheIndex(): void
    {
        $kernel = $this->factory->project->write(self::KERNEL, "<?php\n");
        $other  = $this->factory->project->write('src/Other.php', "<?php\n");
        $json   = '{"totals":{"errors":0,"file_errors":3},"files":{'
            . '"' . $kernel . '":{"errors":2,"messages":[{"message":"a","line":1,"identifier":"i.a"},{"message":"b","line":2,"identifier":"i.b"}]},'
            . '"' . $other . '":{"errors":1,"messages":[{"message":"c","line":3,"identifier":"i.c"}]}'
            . '},"errors":[]}';
        $this->factory->processes->willFail(1, $json);

        new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true)->build()));

        self::assertSame(2, $this->decode($this->reportPath(self::KERNEL))['error_count']);
        self::assertSame(1, $this->decode($this->reportPath('src/Other.php'))['error_count']);

        $lines = $this->stdoutLines();
        self::assertSame('PHPSTAN AGENT MODE: 3 errors in 2 files', $lines[0]);
        self::assertStringEndsWith(FileReportWriter::INDEX_FILE, $lines[1], 'one index path serves however many files had findings');

        $index = $this->decode($this->reportsDir() . '/' . FileReportWriter::INDEX_FILE);
        self::assertSame(2, $index['file_count']);
        self::assertSame(3, $index['error_count']);
    }

    #[Test]
    public function aWholeProjectAgentRunClearsEveryStaleReportBeforeWritingItsOwn(): void
    {
        $kernel = $this->factory->project->write(self::KERNEL, "<?php\n");
        $this->factory->processes->willFail(1, $this->reportFor($kernel, 1));
        new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true)->build()));
        self::assertFileExists($this->reportPath(self::KERNEL));
        $this->factory->stdout->fetch();

        $this->factory->processes->willSucceed('{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}');
        new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true)->build()));

        self::assertFileDoesNotExist($this->reportPath(self::KERNEL), 'an absent report must mean clean, so the stale one has to go');
        self::assertSame('PHPSTAN AGENT MODE: 0 errors in 0 files', $this->stdoutLines()[0]);
    }

    #[Test]
    public function agentModeNeverPrintsTheTautologyNoteOrTheIdentifierTrailerToStdout(): void
    {
        $file = $this->factory->project->write(self::KERNEL, "<?php\n");
        $json = '{"totals":{"errors":0,"file_errors":1},"files":{"' . $file . '":{"errors":1,"messages":[{"message":"always true","line":4,"identifier":"method.alreadyNarrowedType"}]}},"errors":[]}';
        $this->factory->processes->willFail(1, $json);

        new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        $printed = $this->factory->stdout->fetch();
        self::assertStringNotContainsString('possible tautology', $printed);
        self::assertStringNotContainsString(PhpstanTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function anAgentModeCrashIsReportedAsCrashedWithNoDebugReRun(): void
    {
        $this->factory->project->write(self::KERNEL, "<?php\n");
        $this->factory->processes->willFail(255, "PHP Fatal error: boom\n");

        $result = new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertCount(1, $this->factory->processes->specs, 'a crash in agent mode is reported, not re-run for a human to watch');

        $report = $this->decode($this->reportPath(self::KERNEL));
        self::assertSame('crashed', $report['status']);
        self::assertSame(3, $report['exit_code']);
        self::assertStringContainsString('crashed', $this->stdoutLines()[0]);
    }

    #[Test]
    public function unreadableOutputInAgentModeIsACrashRatherThanAGreen(): void
    {
        $this->factory->project->write(self::KERNEL, "<?php\n");
        $this->factory->processes->willSucceed("not json at all\n");

        $result = new PhpstanTool()->run($this->factory->context($this->factory->builder(agentMode: true, specifiedPath: self::KERNEL)->build()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('crashed', $this->decode($this->reportPath(self::KERNEL))['status']);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpstanTool();

        self::assertSame('phpstan', $tool->name());
        self::assertSame('phpqaci.phpstan', $tool->identifier());
        self::assertSame(PhpstanTool::IDENTIFIER, $tool->identifier());
    }

    /** A PHPStan JSON report placing $count findings against one absolute file path. */
    private function reportFor(string $absolutePath, int $count): string
    {
        $messages = [];
        for ($i = 0; $i < $count; ++$i) {
            $messages[] = \sprintf('{"message":"Method foo%d() has no return type specified.","line":%d,"ignorable":true,"identifier":"missingType.return"}', $i, 11 + $i);
        }

        return \sprintf(
            '{"totals":{"errors":0,"file_errors":%d},"files":{"%s":{"errors":%d,"messages":[%s]}},"errors":[]}',
            $count,
            $absolutePath,
            $count,
            implode(',', $messages),
        );
    }

    private function reportsDir(): string
    {
        return $this->factory->project->path . '/' . self::VAR_QA_PREFIX . PhpstanTool::REPORT_DIR;
    }

    private function reportPath(string $projectRelative): string
    {
        return $this->reportsDir() . '/' . $projectRelative . '.json';
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        self::assertFileExists($path);
        $decoded = \Safe\json_decode(\Safe\file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> the non-empty lines agent mode put on the real stdout */
    private function stdoutLines(): array
    {
        return array_values(array_filter(
            explode("\n", trim($this->factory->stdout->fetch())),
            static fn (string $line): bool => '' !== trim($line),
        ));
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
