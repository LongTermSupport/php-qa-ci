<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\PhpCsFixer\FilteredFinderConfig;
use LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool;
use LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpCsFixerTool::class)]
#[UsesClass(ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\FormatterCache::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(IgnoredPaths::class)]
#[UsesClass(FilteredFinderConfig::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class PhpCsFixerToolTest extends TestCase
{
    private const string LINT_ERROR_LINE = "Files that were not fixed due to errors:\n   1) src/Broken.php\n";

    private const string NO_CACHE = '--using-cache=no';

    private const string OUTPUT_LOG = 'var/qa/php-cs-fixer-output.log';

    private const string NOTHING_FIXED = "Fixed 0 of 3 files in 0.010 seconds\n";

    private const string ONE_FIXED = "   1) src/A.php (heredoc_indentation)\n\nFixed 1 of 3 files in 0.010 seconds\n";

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
    public function aReadOnlyRunPassesDryRunWithTheExpectedArgv(): void
    {
        $this->factory->processes->willSucceed("Loaded config default.\n");
        $context = $this->context(readOnly: true);

        $result = new PhpCsFixerTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $this->factory->processes->specs, 'the fixer, no version probe');
        self::assertSame([...$this->expectedArgs($context, self::NO_CACHE), '--dry-run', ...$context->config->pathsToCheck], $this->toolArgs());
        self::assertSame($context->config->paths->projectRoot, $this->factory->processes->lastSpec()->cwd);
        self::assertContains($context->config->paths->pharDir . '/php-cs-fixer.phar', $this->factory->processes->lastSpec()->command);
        self::assertStringContainsString('Running PHP CS Fixer in read-only check mode', $this->factory->output->fetch());
    }

    #[Test]
    public function aWritableRunThatFixedNothingRunsOnceWithTheCacheAndNoDryRun(): void
    {
        $this->factory->processes->willSucceed(self::NOTHING_FIXED);
        $context = $this->context(readOnly: false);

        $result = new PhpCsFixerTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $this->factory->processes->specs);
        self::assertSame([...$this->expectedArgs($context, $this->cacheFile($context)), ...$context->config->pathsToCheck], $this->toolArgs());
        self::assertStringNotContainsString('read-only check mode', $this->factory->output->fetch());
    }

    #[Test]
    public function aWritableRunThatFixedAFileRunsAgainWithoutTheCache(): void
    {
        $this->factory->processes->willSucceed(self::ONE_FIXED)->willSucceed(self::NOTHING_FIXED);
        $context = $this->context(readOnly: false);

        $result = new PhpCsFixerTool()->run($context);

        self::assertTrue($result->isSuccess());
        self::assertCount(2, $this->factory->processes->specs);
        self::assertSame([...$this->expectedArgs($context, $this->cacheFile($context)), ...$context->config->pathsToCheck], $this->toolArgs(0));
        self::assertSame([...$this->expectedArgs($context, self::NO_CACHE), ...$context->config->pathsToCheck], $this->toolArgs(1));
        self::assertStringContainsString('running it again without the cache', $this->factory->output->fetch());
        self::assertSame(self::ONE_FIXED . self::NOTHING_FIXED, $this->factory->project->read(self::OUTPUT_LOG));
    }

    #[Test]
    public function aWritableRunWhoseOutputHasNoSummaryRunsAgainWithoutTheCache(): void
    {
        $this->factory->processes->willSucceed()->willSucceed(self::NOTHING_FIXED);
        $context = $this->context(readOnly: false);

        new PhpCsFixerTool()->run($context);

        self::assertCount(2, $this->factory->processes->specs);
        self::assertContains(self::NO_CACHE, $this->toolArgs(1));
    }

    #[Test]
    public function aFailedUncachedPassFailsTheWritableRun(): void
    {
        $this->factory->processes->willSucceed(self::ONE_FIXED)->willFail(1);

        $result = new PhpCsFixerTool()->run($this->context(readOnly: false));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHP CS Fixer failed (exit 1)', $result->summary);
    }

    #[Test]
    public function aLintErrorInTheUncachedPassCrashesTheWritableRun(): void
    {
        $this->factory->processes->willSucceed(self::ONE_FIXED)->willFail(8, self::LINT_ERROR_LINE);

        $result = new PhpCsFixerTool()->run($this->context(readOnly: false));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
    }

    #[Test]
    public function theOutputIsWrittenToTheLogFile(): void
    {
        $this->factory->processes->willSucceed("Fixed 0 of 3 files\n");

        new PhpCsFixerTool()->run($this->context(readOnly: true));

        self::assertSame("Fixed 0 of 3 files\n", $this->factory->project->read(self::OUTPUT_LOG));
    }

    #[Test]
    public function aReadOnlyPendingFixFailsWithTheRemediation(): void
    {
        $this->factory->processes->willFail(8, "   1) src/A.php\n");

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
        $this->factory->processes->willFail(1);

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
        $this->factory->processes->willFail(8);

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
        $this->factory->processes->willSucceed(self::LINT_ERROR_LINE);

        $result  = new PhpCsFixerTool()->run($this->context(readOnly: true));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('ERROR: PHP CS Fixer encountered linting errors that prevented checking files', $printed);
        self::assertStringContainsString('These errors must be fixed before continuing', $printed);
        self::assertStringContainsString(PhpCsFixerTool::IDENTIFIER, $printed);
        self::assertStringContainsString(self::LINT_ERROR_LINE, $this->factory->project->read(self::OUTPUT_LOG));
    }

    #[Test]
    public function aLintErrorCrashesInAWritableRun(): void
    {
        $this->factory->processes->willFail(8, self::LINT_ERROR_LINE);

        $result  = new PhpCsFixerTool()->run($this->context(readOnly: false));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertStringContainsString('ERROR: PHP CS Fixer encountered linting errors that prevented fixing files', $printed);
    }

    /** @param list<string> $ignored */
    #[Test]
    #[DataProvider('ignoredPaths')]
    public function theFixerRunsAGeneratedConfigWrappingTheResolvedOne(array $ignored): void
    {
        $this->factory->processes->willSucceed();
        $config  = $this->factory->builder(readOnly: true)->withIgnoredPaths(...$ignored)->build();
        $context = $this->factory->context($config);

        new PhpCsFixerTool()->run($context);

        $wrapper = $config->paths->varDir . '/' . PhpCsFixerTool::FINDER_CONFIG;
        self::assertSame('--config=' . $wrapper, $this->toolArgs()[0]);
        self::assertSame(
            new FilteredFinderConfig()->source($context->configPath('php_cs.php'), IgnoredPaths::of($config), $config->paths->varDir . '/' . PhpCsFixerTool::UNRESOLVED_LIST),
            \Safe\file_get_contents($wrapper),
        );
        self::assertSame([...$this->expectedArgs($context, self::NO_CACHE), '--dry-run', ...$config->pathsToCheck], $this->toolArgs());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function ignoredPaths(): iterable
    {
        yield 'no ignored paths' => [[]];
        yield 'ignored paths' => [['tests/assets']];
    }

    #[Test]
    public function eachFileTheFinderCouldNotResolveIsNamedOnceRelativeToTheProjectRoot(): void
    {
        $context = $this->context(readOnly: false);
        $root    = $context->config->paths->projectRoot;
        $list    = $context->config->paths->varDir . '/' . PhpCsFixerTool::UNRESOLVED_LIST;
        $skip    = static function () use ($list, $root): ProcessResultDto {
            \Safe\file_put_contents($list, $root . "/src/Gone.php\n/elsewhere/Away.php\n", \FILE_APPEND);

            return new ProcessResultDto(0, self::ONE_FIXED, '');
        };
        $this->factory->processes->willRun($skip)->willRun($skip);

        $result  = new PhpCsFixerTool()->run($context);
        $printed = $this->factory->output->fetch();

        self::assertTrue($result->isSuccess());
        self::assertSame(1, substr_count($printed, 'PHP CS Fixer skipped src/Gone.php: its real path cannot be resolved'), $printed);
        self::assertSame(1, substr_count($printed, 'PHP CS Fixer skipped /elsewhere/Away.php:'), $printed);
    }

    #[Test]
    public function aListLeftByAnEarlierRunIsNotReported(): void
    {
        $context = $this->context(readOnly: true);
        $this->factory->project->write('var/qa/' . PhpCsFixerTool::UNRESOLVED_LIST, "/stale/Gone.php\n");
        $this->factory->processes->willSucceed();

        new PhpCsFixerTool()->run($context);

        self::assertStringNotContainsString('skipped', $this->factory->output->fetch());
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

    /**
     * @param int|null $index which process, in the order run; the last when null
     *
     * @return list<string> the argv after the `--` separator, i.e. what the fixer itself sees
     */
    private function toolArgs(?int $index = null): array
    {
        $spec      = null === $index ? $this->factory->processes->lastSpec() : $this->factory->processes->specs[$index];
        $command   = $spec->command;
        $separator = array_search('--', $command, true);
        self::assertIsInt($separator);

        return \array_slice($command, $separator + 1);
    }

    private function cacheFile(ToolContext $context): string
    {
        return '--cache-file=' . $context->config->paths->varDir . '/cache/php_cs.cache';
    }

    /** @return list<string> the fixed leading options, before the optional --dry-run and the paths */
    private function expectedArgs(ToolContext $context, string $cache): array
    {
        return [
            '--config=' . $context->config->paths->varDir . '/' . PhpCsFixerTool::FINDER_CONFIG,
            $cache,
            '--allow-risky=yes',
            '--show-progress=dots',
            '--path-mode=intersection',
            '-vvv',
            'fix',
        ];
    }
}
