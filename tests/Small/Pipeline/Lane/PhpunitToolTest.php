<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\Phpunit\PhpunitArguments;
use LTS\PHPQA\Pipeline\Lane\PhpunitTool;
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
#[CoversClass(PhpunitTool::class)]
#[UsesClass(PhpunitArguments::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class PhpunitToolTest extends TestCase
{
    private const string TESTS_BOOTSTRAP_PHP = 'tests/bootstrap.php';

    private const string PHP = '<?php
';

    private const string PHPUNIT_LOGS_PHPUNIT_JUNIT_XML = 'var/qa/phpunit_logs/phpunit.junit.xml';

    private const string PHP_BIN_PATH = '/usr/bin/php';

    private const string MEMORY_LIMIT_ARG = 'memory_limit=4G';

    private const string BIN_PHPUNIT = '/vendor/bin/phpunit';

    private const string TESTS_UNIT = '/tests/Unit';

    private const string VERSION_LINE = "PHPUnit 12.3.4 by Sebastian Bergmann and contributors.\n";

    private const string JUNIT_WITH_TESTS = "<?xml version=\"1.0\"?>\n<testsuites><testsuite name=\"t\" tests=\"1\"/></testsuites>\n";

    private const string IDENTIFIER_FOOTER = "\n🪪  phpqaci.phpunit  (vendor/bin/rule-doc phpqaci.phpunit)\n";

    private const string RUN_PREAMBLE = "PHPUnit Major Version: 12\nChecking for paratest\n";

    private const string ARCHIVAL = "\nLog Archival\n============\n"
        . "Full test suite run\nLog: phpunit.junit.{TS}.xml\n"
        . "\n"
        . "Full test suite run\nLog: phpunit.{TS}.log\n";

    private ContextFactory $factory;

    private string $root;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->root    = $this->factory->project->path;
        $this->factory->project->write(self::TESTS_BOOTSTRAP_PHP, self::PHP);
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCoverageRunInCiUsesTheXdebugBinaryAndTheExactArgv(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed("\x1B[30;42mOK\x1B[0m\nTests: 3, Assertions: 7, Failures: 0.\n");

        $result  = new PhpunitTool()->run($this->context($this->factory->builder(ci: true)));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertCount(2, $this->factory->processes->specs);

        $probe = $this->factory->processes->specs[0];
        self::assertSame([self::PHP_BIN_PATH, '-d', self::MEMORY_LIMIT_ARG, '-f', $this->root . self::BIN_PHPUNIT, '--', '--version'], $probe->command);
        self::assertFalse($probe->streamOutput);

        $run = $this->factory->processes->specs[1];
        self::assertSame([
            self::PHP_BIN_PATH, '-d', self::MEMORY_LIMIT_ARG, '-f', $this->root . self::BIN_PHPUNIT, '--',
            '-c', $this->configPath(),
            '--strict-global-state', '--fail-on-risky', '--fail-on-warning',
            '--log-junit', $this->root . '/var/qa/phpunit_logs/phpunit.junit.xml',
            '--colors=always', '--display-incomplete', '--display-skipped', '--display-deprecations',
            '--display-phpunit-deprecations', '--display-errors', '--display-notices', '--display-phpunit-notices', '--display-warnings',
        ], $run->command);
        self::assertSame(['phpUnitQuickTests' => '0', 'XDEBUG_MODE' => 'coverage'], $run->env);
        self::assertSame($this->root, $run->cwd);
        self::assertTrue($run->streamOutput);

        $this->assertPrinted(self::RUN_PREAMBLE
        . self::ARCHIVAL
        . "Result: Tests: 3, Assertions: 7, Failures: 0.\n", $printed, 'the summary line is printed with the colour codes stripped, and no identifier on a pass');
        self::assertStringContainsString('Tests: 3', $this->factory->project->read('var/qa/phpunit_logs/phpunit.log'));
    }

    #[Test]
    public function aSummaryLineWithACarriageReturnIsPrintedWithoutIt(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed("OK\r\nTests: 3, Assertions: 7.\r\n");

        new PhpunitTool()->run($this->context($this->factory->builder()));

        self::assertStringEndsWith("Result: Tests: 3, Assertions: 7.\n", $this->factory->output->fetch());
    }

    #[Test]
    public function aNoCoverageRunStripsXdebugAndAddsTheNoCoverageFlags(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed('OK');

        $config = $this->factory->builder(env: ['phpUnitCoverage' => '0', 'phpUnitQuickTests' => '1'], ci: true);
        $result = new PhpunitTool()->run($this->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $run = $this->factory->processes->lastSpec();
        self::assertSame(self::PHP_BIN_PATH, $run->command[0]);
        self::assertSame('-d', $run->command[1]);
        self::assertContains('--no-coverage', $run->command);
        self::assertContains('--enforce-time-limit', $run->command);
        self::assertSame(['phpUnitQuickTests' => '1', 'XDEBUG_MODE' => 'off'], $run->env);
    }

    #[Test]
    public function aSpecifiedPathIsAppendedAndArchivedAsPathSpecific(): void
    {
        $this->factory->project->write('src/Thing.php', self::PHP);
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed('OK');

        $result  = new PhpunitTool()->run($this->context($this->factory->builder(specifiedPath: 'tests/Unit')));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame($this->root . self::TESTS_UNIT, array_last($this->factory->processes->lastSpec()->command));
        self::assertStringContainsString('Running PHPUnit on specified paths: ' . $this->root . self::TESTS_UNIT, $printed);
        self::assertStringContainsString('Path-specific run: ' . $this->root . self::TESTS_UNIT, $printed);
    }

    #[Test]
    public function paratestIsPreferredWhenInstalled(): void
    {
        $this->factory->project->write('vendor/bin/paratest', "#!/usr/bin/env php\n");
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed('OK');

        new PhpunitTool()->run($this->context($this->factory->builder()));

        $run = $this->factory->processes->lastSpec();
        self::assertSame($this->root . '/vendor/bin/paratest', $run->command[4]);
        self::assertSame(['--phpunit', $this->root . self::BIN_PHPUNIT], \array_slice($run->command, 6, 2));
        self::assertStringContainsString('Found paratest, using this instead of standard ' . $this->root . self::BIN_PHPUNIT, $this->factory->output->fetch());
    }

    #[Test]
    public function aMissingBootstrapIsSeededWithThePlaceholder(): void
    {
        \Safe\unlink($this->root . '/tests/bootstrap.php');
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed('OK');

        new PhpunitTool()->run($this->context($this->factory->builder()));
        $printed = $this->factory->output->fetch();

        self::assertStringContainsString('Creating placeholder bootstrap file at ' . $this->root . '/tests/bootstrap.php', $printed);
        self::assertStringContainsString('Placeholder bootstrap file created. Please customize it for your project needs.', $printed);
        $bootstrap = $this->factory->project->read(self::TESTS_BOOTSTRAP_PHP);
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $bootstrap);
        self::assertStringContainsString("require dirname(__DIR__) . '/vendor/autoload.php';", $bootstrap);
        self::assertStringContainsString('REPLACE THIS FILE with your project-specific bootstrap logic.', $bootstrap);
    }

    #[Test]
    public function anExistingBootstrapIsLeftAlone(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed('OK');

        new PhpunitTool()->run($this->context($this->factory->builder()));

        self::assertSame(self::PHP, $this->factory->project->read(self::TESTS_BOOTSTRAP_PHP));
        self::assertStringNotContainsString('Creating placeholder bootstrap', $this->factory->output->fetch());
    }

    #[Test]
    public function aMissingJunitLogMeansNoTestsRanAndFails(): void
    {
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willSucceed('OK');

        $result  = new PhpunitTool()->run($this->context($this->factory->builder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHPUnit Tests failed (exit 1)', $result->summary, 'a run with no tests is a plain failure, exit 1');
        $this->assertPrinted(self::RUN_PREAMBLE
        . "\n"
        . "        ERROR - no tests have been run!\n"
        . "\n"
        . "        Please ensure you have at least one valid test suite configured in your phpunit.xml file\n"
        . "\n"
        . "\n"
        . "Log Archival\n"
        . "============\n"
        . "\n"
        . "Full test suite run\n"
        . "Log: phpunit.{TS}.log\n"
        . self::IDENTIFIER_FOOTER, $printed, 'with no junit log there is nothing to archive under it, only the stdout log');
    }

    #[Test]
    public function anEmptyTestsuitesElementMeansNoTestsRanEvenAfterACrash(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, "<?xml version=\"1.0\"?>\n<testsuites/>\n");
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willFail(255, 'Fatal error');

        $result = new PhpunitTool()->run($this->context($this->factory->builder()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, 'the no-tests guard overrides the exit code, as the fragment did');
        self::assertCount(2, $this->factory->processes->specs, 'no diagnostic re-run once the guard has decided');
        self::assertStringContainsString('ERROR - no tests have been run!', $this->factory->output->fetch());
    }

    #[Test]
    public function aTestFailureIsFailedWithTheIdentifierTrailer(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willFail(1, "FAILURES!\nTests: 3, Assertions: 5, Failures: 1.\n");

        $result  = new PhpunitTool()->run($this->context($this->factory->builder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHPUnit Tests failed (exit 1)', $result->summary);
        self::assertStringContainsString('Result: Tests: 3, Assertions: 5, Failures: 1.', $printed);
        self::assertStringContainsString(PhpunitTool::IDENTIFIER, $printed);
        self::assertCount(2, $this->factory->processes->specs);
    }

    #[Test]
    public function anErrorExitOfTwoIsAlsoAFailure(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes->willSucceed(self::VERSION_LINE)->willFail(2, 'ERRORS!');

        $result = new PhpunitTool()->run($this->context($this->factory->builder()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
    }

    #[Test]
    public function aCrashIsReRunWithDebugAndNeverRetried(): void
    {
        $this->factory->project->write(self::PHPUNIT_LOGS_PHPUNIT_JUNIT_XML, self::JUNIT_WITH_TESTS);
        $this->factory->processes
            ->willSucceed(self::VERSION_LINE)
            ->willFail(255, 'PHP Fatal error: boom')
            ->willFail(255, 'boom again')
        ;

        $result  = new PhpunitTool()->run($this->context($this->factory->builder()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('PHPUnit crashed (exit 255)', $result->summary);
        $this->assertPrinted(self::RUN_PREAMBLE
        . self::ARCHIVAL
        . "\n\n\n"
        . "PHPUnit Crashed\n"
        . "\n"
        . "Running again with Debug mode...\n"
        . "\n\n"
        . self::IDENTIFIER_FOOTER, $printed);

        $debug = $this->factory->processes->lastSpec();
        self::assertSame(
            [self::PHP_BIN_PATH, '-d', self::MEMORY_LIMIT_ARG, '-f', $this->root . self::BIN_PHPUNIT, '--', $this->root . '/tests', '--debug'],
            $debug->command,
        );
        self::assertSame(['qaQuickTests' => '0', 'XDEBUG_MODE' => 'off'], $debug->env);
    }

    #[Test]
    public function anUnparseableVersionProbeIsACrash(): void
    {
        $this->factory->processes->willFail(1, "  Could not open input file\n");

        $result = new PhpunitTool()->run($this->context($this->factory->builder()));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('PHPUnit version probe failed', $result->summary);
        self::assertCount(1, $this->factory->processes->specs);
        self::assertSame(
            "ERROR: could not determine the PHPUnit version from: Could not open input file\n" . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
            'the probe output is quoted trimmed, after the message, and the identifier follows',
        );
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpunitTool();

        self::assertSame('phpunit', $tool->name());
        self::assertSame('phpqaci.phpunit', $tool->identifier());
    }

    private function context(QaConfigBuilder $builder): ToolContext
    {
        return $this->factory->context($builder->build());
    }

    /** The whole output, where `{TS}` stands for an archive's timestamp. */
    private function assertPrinted(string $expected, string $printed, string $message = ''): void
    {
        $pattern = str_replace(preg_quote('{TS}', '/'), '\d{8}-\d{6}', preg_quote($expected, '/'));

        self::assertMatchesRegularExpression('/\A' . $pattern . '\z/', $printed, $message);
    }

    private function configPath(): string
    {
        return \dirname(__DIR__, 4) . '/configDefaults/generic/phpunit.xml';
    }
}
