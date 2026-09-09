<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Lane\PhpArkitectTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
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
#[CoversClass(PhpArkitectTool::class)]
#[UsesClass(ConfigPathResolver::class)]
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
    public function aCleanRunPassesTheConfigAutoloadAndTierEnvironment(): void
    {
        $this->queueVersion()->willSucceed("✓ no violations\n");
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
                'PHPQACI_ARKITECT_SRC_DIR'                => $this->factory->project->path . '/src',
                'PHPQACI_ARKITECT_RULES_DEFAULT'          => $defaults . '/phparkitect-rules-default.php',
                'PHPQACI_ARKITECT_RULES_OPTIONAL'         => $defaults . '/phparkitect-rules-optional.php',
                'PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY' => $defaults . '/phparkitect-rules-optional-symfony.php',
                'PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY'  => $defaults . '/phparkitect-consumer-api-boundary.php',
                'PHPQACI_ARKITECT_EXCLUDE_PATHS'          => "Quote/API\nGenerated/Client",
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
        $this->queueVersion()->willSucceed();

        new PhpArkitectTool()->run($this->factory->context());

        self::assertSame('', $this->factory->processes->lastSpec()->env['PHPQACI_ARKITECT_EXCLUDE_PATHS']);
    }

    #[Test]
    public function projectOverridesOfTheEntryConfigAndTiersWin(): void
    {
        $entry   = $this->factory->project->write('qaConfig/phparkitect.php', "<?php return static fn () => null;\n");
        $default = $this->factory->project->write('qaConfig/phparkitect-rules-default.php', "<?php return [];\n");
        $this->queueVersion()->willSucceed();

        new PhpArkitectTool()->run($this->factory->context());

        $spec = $this->factory->processes->lastSpec();
        self::assertContains('--config=' . $entry, $this->toolArgs($spec));
        self::assertSame($default, $spec->env['PHPQACI_ARKITECT_RULES_DEFAULT']);
        self::assertSame(\dirname(__DIR__, 4) . '/configDefaults/generic/phparkitect-rules-optional.php', $spec->env['PHPQACI_ARKITECT_RULES_OPTIONAL']);
    }

    #[Test]
    public function ruleViolationsFailWithTheIdentifier(): void
    {
        $this->queueVersion()->willFail(1, "App\\Foo violates ...\n");

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
        $this->queueVersion()->willFail(2, "Parse error in src/Broken.php\n");

        $result  = new PhpArkitectTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertFalse($result->outcome->isRetryable());
        self::assertStringContainsString('PHPArkitect crashed (exit 2) — this is an ERROR, not a rule violation.', $printed);
        self::assertStringContainsString('an INCOMPLETE autoloader does NOT crash', $printed);
        self::assertStringContainsString("run 'composer dump-autoload'", $printed);
        self::assertStringNotContainsString(PhpArkitectTool::IDENTIFIER, $printed);
        self::assertCount(2, $this->factory->processes->specs);
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
