<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Agent\FileReportWriter;
use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Lane\PhpArkitectTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
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
#[CoversClass(PhpArkitectTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\AgentStatusEnum::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\ArkitectJsonParser::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\ClassFileLocator::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Dto\ParsedArchReportDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException::class)]
#[UsesClass(FileReportWriter::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Agent\TerseReporter::class)]
#[UsesClass(ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(LogArchiver::class)]
#[UsesClass(PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[Small]
final class PhpArkitectToolTest extends TestCase
{
    private const string CLASS_NAMESPACE = 'App';

    private const string CLASS_SHORT_NAME = 'BadlyNamed';

    private const string CLASS_FILE = 'src/BadlyNamed.php';

    private const string MISSING_SUFFIX_MESSAGE = 'a name suffix is missing';

    private const string DEEP_CLASS_FILE = 'src/Deep/BadlyNamed.php';

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
    public function aCleanRunPassesTheConfigAutoloadAndTierEnvironment(): void
    {
        $this->factory->processes->willSucceed("✓ no violations\n");
        $config = $this->factory->builder()->withArkitectExcludedPaths('Quote/API', 'Generated/Client')->build();

        $result  = new PhpArkitectTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $defaults = \dirname(__DIR__, 4) . '/configDefaults/generic';
        self::assertStringContainsString('PHPArkitect: using config ' . $defaults . '/phparkitect.php', $printed);

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(\dirname(__DIR__, 4) . '/vendor-phar/phparkitect.phar', $this->script($spec));
        self::assertSame(
            [
                'check',
                '--config=' . $defaults . '/phparkitect.php',
                '--autoload=' . $this->factory->project->path . '/vendor/autoload.php',
                '--no-interaction',
            ],
            $this->toolArgs($spec),
        );
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertTrue($spec->streamOutput);
        self::assertSame(
            [
                'PHPQACI_ARKITECT_SRC_DIR'                 => $this->factory->project->path . '/src',
                'PHPQACI_ARKITECT_RULES_DEFAULT'           => $defaults . '/phparkitect-rules-default.php',
                'PHPQACI_ARKITECT_RULES_OPTIONAL'          => $defaults . '/phparkitect-rules-optional.php',
                'PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY'  => $defaults . '/phparkitect-rules-optional-symfony.php',
                'PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY'   => $defaults . '/phparkitect-consumer-api-boundary.php',
                'PHPQACI_ARKITECT_EXCLUDE_PATHS'           => "Quote/API\nGenerated/Client",
                'XDEBUG_MODE'                              => 'off',
            ],
            $spec->env,
        );

        $logDir = 'var/qa/' . PhpArkitectTool::LOG_DIR;
        self::assertSame("✓ no violations\n", $this->factory->project->read($logDir . '/' . PhpArkitectTool::LOG_FILE));
        $archived = array_filter($this->factory->project->files($logDir), static fn (string $f): bool => 1 === \Safe\preg_match('/^phparkitect\.\d{8}-\d{6}\.log$/', $f));
        self::assertCount(1, $archived, 'the log is archived with a timestamp');
        self::assertStringContainsString('Full test suite run', $printed);
    }

    #[Test]
    public function noExcludePathsExportsAnEmptyString(): void
    {
        $this->factory->processes->willSucceed();

        new PhpArkitectTool()->run($this->factory->context());

        self::assertSame('', $this->factory->processes->lastSpec()->env['PHPQACI_ARKITECT_EXCLUDE_PATHS']);
    }

    #[Test]
    public function projectOverridesOfTheEntryConfigAndTiersWin(): void
    {
        $entry   = $this->factory->project->write('qaConfig/phparkitect.php', "<?php return static fn () => null;\n");
        $default = $this->factory->project->write('qaConfig/phparkitect-rules-default.php', "<?php return [];\n");
        $this->factory->processes->willSucceed();

        new PhpArkitectTool()->run($this->factory->context());

        $spec = $this->factory->processes->lastSpec();
        self::assertContains('--config=' . $entry, $this->toolArgs($spec));
        self::assertSame($default, $spec->env['PHPQACI_ARKITECT_RULES_DEFAULT']);
        self::assertSame(\dirname(__DIR__, 4) . '/configDefaults/generic/phparkitect-rules-optional.php', $spec->env['PHPQACI_ARKITECT_RULES_OPTIONAL']);
    }

    #[Test]
    public function ruleViolationsFailWithTheIdentifier(): void
    {
        $this->factory->processes->willFail(1, "App\\Foo violates ...\n");

        $result  = new PhpArkitectTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(PhpArkitectTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('crashed', $printed);
        self::assertSame("App\\Foo violates ...\n", $this->factory->project->read('var/qa/' . PhpArkitectTool::LOG_DIR . '/' . PhpArkitectTool::LOG_FILE));
    }

    #[Test]
    public function aCrashPrintsTheExplanationAndIsNeverRetried(): void
    {
        $this->factory->processes->willFail(2, "Parse error in src/Broken.php\n");

        $result  = new PhpArkitectTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertFalse($result->outcome->isRetryable());
        self::assertStringContainsString('PHPArkitect crashed (exit 2) — this is an ERROR, not a rule violation.', $printed);
        self::assertStringContainsString('an INCOMPLETE autoloader does NOT crash', $printed);
        self::assertStringContainsString("run 'composer dump-autoload'", $printed);
        self::assertStringNotContainsString(PhpArkitectTool::IDENTIFIER, $printed);
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function disabledByConfigSkipsWithoutRunningAnything(): void
    {
        $result = new PhpArkitectTool()->run($this->factory->context($this->factory->builder()->withArkitect(false)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('PHPArkitect: disabled (useArkitect=0) — skipping.', $this->factory->output->fetch());
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aMissingEntryConfigSkips(): void
    {
        $defaultsDir = $this->factory->project->mkdir('empty-defaults/generic');
        $paths       = $this->factory->paths();
        $config      = $this->factory->builder()->build();
        $context     = new ToolContext(
            config: $config,
            configPaths: new ConfigPathResolver($paths->projectConfigDir, \dirname($defaultsDir), $config->platform),
            processes: $this->factory->processes,
            php: new PhpInvoker($this->factory->processes, $config->phpBinPath, $config->memoryLimit, $paths->varDir),
            logs: new LogArchiver($this->factory->output),
            output: $this->factory->output,
            stdout: $this->factory->stdout,
        );

        $result = new PhpArkitectTool()->run($context);

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('PHPArkitect: no entry config resolved at ' . $defaultsDir . '/phparkitect.php — skipping.', $this->factory->output->fetch());
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpArkitectTool();

        self::assertSame('phpArkitect', $tool->name());
        self::assertSame('phpqaci.phpArkitect', $tool->identifier());
        self::assertSame(PhpArkitectTool::IDENTIFIER, $tool->identifier());
    }

    #[Test]
    public function agentModeAsksArkitectForJsonAndKeepsStdoutToThreeLines(): void
    {
        $this->violatingClass(self::CLASS_FILE, self::CLASS_NAMESPACE, self::CLASS_SHORT_NAME);
        $this->factory->processes->willFail(1, $this->report(['App\BadlyNamed' => [self::MISSING_SUFFIX_MESSAGE]]));

        $result = new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));
        $lines  = $this->stdoutLines();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(3, $lines);
        self::assertSame('PHPARKITECT AGENT MODE: 1 violation in 1 file', $lines[0]);
        self::assertStringEndsWith(FileReportWriter::INDEX_FILE, $lines[1]);
        self::assertStringContainsString('ACTION REQUIRED', $lines[2]);
        self::assertContains('--format=json', $this->toolArgs($this->factory->processes->lastSpec()));
    }

    #[Test]
    public function aViolatingClassIsReportedAgainstTheFileThatDeclaresIt(): void
    {
        $this->violatingClass(self::DEEP_CLASS_FILE, 'App\Deep', self::CLASS_SHORT_NAME);
        $this->factory->processes->willFail(1, $this->report(['App\Deep\BadlyNamed' => [self::MISSING_SUFFIX_MESSAGE, 'and it should be final']]));

        new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));

        $report = $this->decode($this->reportPath(self::DEEP_CLASS_FILE));
        self::assertSame('phpArkitect', $report['tool']);
        self::assertSame(self::DEEP_CLASS_FILE, $report['path']);
        self::assertSame('errors', $report['status']);
        self::assertSame(2, $report['error_count']);
        $errors = $report['errors'];
        self::assertIsArray($errors);
        $first = $errors[0];
        self::assertIsArray($first);
        self::assertSame(self::MISSING_SUFFIX_MESSAGE, $first['message']);
        self::assertNull($first['line'], 'PHPArkitect reports per class and gives no line');
    }

    #[Test]
    public function aClassTheSetCannotResolveFallsBackToAPerRuleReportRatherThanBeingLost(): void
    {
        $this->factory->processes->willFail(1, $this->report(['Vendor\Absent' => [self::MISSING_SUFFIX_MESSAGE]]));

        new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));

        $fallback = $this->reportsDir() . '/' . PhpArkitectTool::RULE_FALLBACK_DIR . '/a-name-suffix-is-missing.json';
        $report   = $this->decode($fallback);
        self::assertSame(1, $report['error_count']);
        $errors = $report['errors'];
        self::assertIsArray($errors);
        $first = $errors[0];
        self::assertIsArray($first);
        $message = $first['message'];
        self::assertIsString($message);
        self::assertSame(
            'Vendor\Absent ' . self::MISSING_SUFFIX_MESSAGE,
            $message,
            'the class name leads the message, because the report is named for the rule and would not otherwise say what broke it',
        );
        $tip = $first['tip'];
        self::assertIsString($tip);
        self::assertStringContainsString('grouped by rule', $tip);
    }

    #[Test]
    public function aCleanAgentRunClearsEveryStaleReportSoAnAbsentOneMeansClean(): void
    {
        $this->violatingClass(self::CLASS_FILE, self::CLASS_NAMESPACE, self::CLASS_SHORT_NAME);
        $this->factory->processes->willFail(1, $this->report(['App\BadlyNamed' => [self::MISSING_SUFFIX_MESSAGE]]));
        new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));
        self::assertFileExists($this->reportPath(self::CLASS_FILE));
        $this->factory->stdout->fetch();

        $this->factory->processes->willSucceed('{"totalViolations": 0, "details": []}');

        $result = new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertFileDoesNotExist($this->reportPath(self::CLASS_FILE));
        self::assertSame('PHPARKITECT AGENT MODE: 0 violations in 0 files', $this->stdoutLines()[0]);
    }

    #[Test]
    public function agentModeNeverPrintsTheIdentifierTrailerToStdout(): void
    {
        $this->violatingClass(self::CLASS_FILE, self::CLASS_NAMESPACE, self::CLASS_SHORT_NAME);
        $this->factory->processes->willFail(1, $this->report(['App\BadlyNamed' => [self::MISSING_SUFFIX_MESSAGE]]));

        new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));

        self::assertStringNotContainsString(PhpArkitectTool::IDENTIFIER, $this->factory->stdout->fetch());
    }

    #[Test]
    public function anAgentModeCrashIsReportedAsCrashedWithoutTheHumanTroubleshootingEssay(): void
    {
        $this->factory->processes->willFail(255, "PHP Fatal error: bad config\n");

        $result = new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('crashed', $this->stdoutLines()[0]);
        self::assertStringNotContainsString('composer dump-autoload', $this->factory->stdout->fetch());
    }

    #[Test]
    public function unreadableOutputInAgentModeIsACrashRatherThanAGreen(): void
    {
        $this->factory->processes->willSucceed("not json at all\n");

        $result = new PhpArkitectTool()->run($this->factory->context($this->agentConfig()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
    }

    #[Test]
    public function agentModeStillHonoursTheDisableSwitch(): void
    {
        $result = new PhpArkitectTool()->run($this->factory->context($this->factory->builder(agentMode: true)->withArkitect(false)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
    }

    private function agentConfig(): \LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto
    {
        return $this->factory->builder(agentMode: true)->build();
    }

    /**
     * A PHPArkitect --format=json report, wrapped in the banner and trailer the phar really prints.
     *
     * @param array<string, list<string>> $violationsByClass
     */
    private function report(array $violationsByClass): string
    {
        $details = [];
        $total   = 0;
        foreach ($violationsByClass as $fqcn => $messages) {
            $details[$fqcn] = array_map(static fn (string $m): array => ['error' => $m], $messages);
            $total += \count($messages);
        }

        return "PHPArkitect 1.3.0.0\n\nanalyze class set\n 10/10 [====] 100%\n\n"
            . \Safe\json_encode(['totalViolations' => $total, 'details' => $details])
            . "\n⚠️ violations detected!\n";
    }

    private function violatingClass(string $relative, string $namespace, string $shortName): void
    {
        $this->factory->project->write($relative, "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $namespace . ";\n\ninterface " . $shortName . "\n{\n}\n");
    }

    private function reportsDir(): string
    {
        return $this->factory->project->path . '/var/qa/' . PhpArkitectTool::REPORT_DIR;
    }

    private function reportPath(string $projectRelative): string
    {
        return $this->reportsDir() . '/' . $projectRelative . '.json';
    }

    /** @return array<array-key, mixed> */
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
