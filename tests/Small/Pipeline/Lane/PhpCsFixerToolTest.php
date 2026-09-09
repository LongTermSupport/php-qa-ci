<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool;
use LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance;
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
#[CoversClass(PhpCsFixerTool::class)]
#[UsesClass(ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[Small]
final class PhpCsFixerToolTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    private const string LINT_ERROR_LINE = "Files that were not fixed due to errors:\n   1) src/Broken.php\n";

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write('var/qa/phpqa-no-xdebug.8.5.10.ini', '');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aReadOnlyRunPassesDryRunWithTheExpectedArgv(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed("Loaded config default.\n");
        $context = $this->context(readOnly: true);

        $result = new PhpCsFixerTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(2, $this->factory->processes->specs, 'the version probe and the fixer');
        self::assertSame([...$this->expectedArgs($context), '--dry-run', ...$context->config->pathsToCheck], $this->toolArgs());
        self::assertSame($context->config->paths->projectRoot, $this->factory->processes->lastSpec()->cwd);
        self::assertContains($context->config->paths->pharDir . '/php-cs-fixer.phar', $this->factory->processes->lastSpec()->command);
        self::assertStringContainsString('Running PHP CS Fixer in read-only check mode', $this->factory->output->fetch());
    }

    #[Test]
    public function aWritableRunOmitsDryRun(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed();
        $context = $this->context(readOnly: false);

        $result = new PhpCsFixerTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertSame([...$this->expectedArgs($context), ...$context->config->pathsToCheck], $this->toolArgs());
        self::assertStringNotContainsString('read-only check mode', $this->factory->output->fetch());
    }

    #[Test]
    public function theOutputIsWrittenToTheLogFile(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed("Fixed 0 of 3 files\n");

        new PhpCsFixerTool()->run($this->context(readOnly: true));

        self::assertSame("Fixed 0 of 3 files\n", $this->factory->project->read('var/qa/php-cs-fixer-output.log'));
    }

    #[Test]
    public function aReadOnlyPendingFixFailsWithTheRemediation(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(8, "   1) src/A.php\n");

        $result  = new PhpCsFixerTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('PHP CS Fixer: pending changes in a READ-ONLY run', $printed);
        self::assertStringContainsString('QA_READONLY=0 vendor/bin/qa -t fixer', $printed);
        self::assertStringContainsString(PhpCsFixerTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aReadOnlyGenuineErrorCrashes(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(1);

        $result  = new PhpCsFixerTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('PHP CS Fixer failed with exit code 1 (a genuine error, not a pending-fix diff)', $printed);
        self::assertStringContainsString(PhpCsFixerTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('pending changes in a READ-ONLY run', $printed);
    }

    #[Test]
    public function aWritableNonZeroExitFailsSoTheRunnerCanRetry(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(8);

        $result  = new PhpCsFixerTool()->run($this->context(readOnly: false));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHP CS Fixer failed (exit 8)', $result->summary);
        self::assertStringContainsString(PhpCsFixerTool::IDENTIFIER, $printed);
        self::assertStringNotContainsString('pending changes in a READ-ONLY run', $printed);
    }

    #[Test]
    public function aLintErrorCrashesInAReadOnlyRunEvenWhenTheExitCodeIsZero(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed(self::LINT_ERROR_LINE);

        $result  = new PhpCsFixerTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('ERROR: PHP CS Fixer encountered linting errors that prevented checking files', $printed);
        self::assertStringContainsString('These errors must be fixed before continuing', $printed);
        self::assertStringContainsString(PhpCsFixerTool::IDENTIFIER, $printed);
        self::assertStringContainsString(self::LINT_ERROR_LINE, $this->factory->project->read('var/qa/php-cs-fixer-output.log'));
    }

    #[Test]
    public function aLintErrorCrashesInAWritableRun(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(8, self::LINT_ERROR_LINE);

        $result  = new PhpCsFixerTool()->run($this->context(readOnly: false));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('ERROR: PHP CS Fixer encountered linting errors that prevented fixing files', $printed);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpCsFixerTool();

        self::assertSame('phpCsFixer', $tool->name());
        self::assertSame('phpqaci.phpCsFixer', $tool->identifier());
    }

    private function context(bool $readOnly): ToolContext
    {
        return $this->factory->context($this->factory->builder(readOnly: $readOnly)->build());
    }

    /** @return list<string> the argv after the `--` separator, i.e. what the fixer itself sees */
    private function toolArgs(): array
    {
        $command   = $this->factory->processes->lastSpec()->command;
        $separator = array_search('--', $command, true);
        self::assertIsInt($separator);

        return \array_slice($command, $separator + 1);
    }

    /** @return list<string> the fixed leading options, before the optional --dry-run and the paths */
    private function expectedArgs(ToolContext $context): array
    {
        return [
            '--config=' . $context->configPath('php_cs.php'),
            '--cache-file=' . $context->config->paths->varDir . '/cache/php_cs.cache',
            '--allow-risky=yes',
            '--show-progress=dots',
            '--path-mode=intersection',
            '-vvv',
            'fix',
        ];
    }
}
