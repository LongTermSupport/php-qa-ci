<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\ComposerChecksTool;
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
#[CoversClass(ComposerChecksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecks\RedundantSuggestDetector::class)]
#[UsesClass(\LTS\PHPQA\Helper::class)]
#[Small]
final class ComposerChecksToolTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    private const string DIAGNOSE = 'diagnose';

    private const string NORMALIZE = 'normalize';

    private const string COMPOSER_ALLOWED = 'true';

    private const string COMPOSER = '/usr/local/bin/composer';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        // Pre-generate the no-Xdebug ini so PhpInvoker only probes the PHP version per invocation.
        $this->factory->project->write('var/qa/phpqa-no-xdebug.8.5.10.ini', '');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aReadOnlyRunDiagnosesChecksThePluginDryRunsNormalizeAndDumpsTheAutoloader(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed('diagnose ok')
            ->willSucceed(self::PHP_VERSION)->willSucceed('No security vulnerability advisories found.')
            ->willSucceed(self::PHP_VERSION)->willSucceed("true\n")
            ->willSucceed(self::PHP_VERSION)->willSucceed('composer.json is already normalized.')
            ->willSucceed(self::PHP_VERSION)->willSucceed('Generated autoload files')
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: true)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            [
                [self::DIAGNOSE],
                ['audit', '--locked', '--abandoned=report', '--no-interaction'],
                ['config', 'allow-plugins.ergebnis/composer-normalize'],
                [self::NORMALIZE, '--dry-run'],
                ['dump-autoload'],
            ],
            $this->composerInvocations(),
        );
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Auditing Dependencies For Known Advisories', $printed);
        self::assertStringContainsString('Checking Composer Normalisation (read-only)', $printed);
        self::assertStringContainsString('Dumping Composer Autoloader', $printed);
        self::assertStringNotContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aWritableRunAppliesNormalize(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed(self::COMPOSER_ALLOWED)
            ->willSucceed(self::PHP_VERSION)->willSucceed('Successfully normalized')
            ->willSucceed(self::PHP_VERSION)->willSucceed()
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: false)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([self::NORMALIZE], $this->composerInvocations()[3]);
        self::assertStringContainsString('Running Composer Normalise', $this->factory->output->fetch());
    }

    #[Test]
    public function everyComposerCallGoesThroughPhpWithoutXdebugFromTheProjectRoot(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed(self::COMPOSER_ALLOWED)
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
        ;
        $root = $this->factory->project->path;

        new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        $first = $this->factory->processes->specs[1];
        self::assertSame(
            ['/usr/bin/php', '-n', '-c', $root . '/var/qa/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G', '-f', self::COMPOSER, '--', self::DIAGNOSE],
            $first->command,
        );
        self::assertSame($root, $first->cwd);
        self::assertFalse($this->factory->processes->specs[5]->streamOutput, 'the allow-plugins probe is captured, not streamed');
    }

    #[Test]
    public function aFailingDiagnoseIsInformationalOnly(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willFail(1, 'Checking platform settings: FAIL')
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed(self::COMPOSER_ALLOWED)
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('composer diagnose reported issues (shown above) — informational only, not failing the pipeline.', $this->factory->output->fetch());
    }

    #[Test]
    public function aKnownAdvisoryFailsTheLaneWithTheRemediationBeforeThePluginCheck(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willFail(1, 'Found 1 security vulnerability advisory affecting 1 package')
        ;

        $result  = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer audit found advisories or could not run (exit 1)', $result->summary);
        self::assertCount(2, $this->composerInvocations(), 'nothing after the audit runs');
        self::assertStringContainsString('composer update <vendor/package> --with-all-dependencies', $printed);
        self::assertStringContainsString('config.audit.ignore', $printed);
        self::assertStringContainsString('withComposerAudit(false)', $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function theAuditIsSkippedWithANoteWhenDisabledForTheProject(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed(self::COMPOSER_ALLOWED)
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
        ;

        $config = $this->factory->builder()->withComposerAudit(false)->build();
        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            [[self::DIAGNOSE], ['config', 'allow-plugins.ergebnis/composer-normalize'], [self::NORMALIZE, '--dry-run'], ['dump-autoload']],
            $this->composerInvocations(),
        );
        self::assertStringContainsString('composer audit disabled for this project', $this->factory->output->fetch());
    }

    #[Test]
    public function aSuggestEntryNamingAnAlreadyRequiredPackageFailsBeforeThePluginProbe(): void
    {
        $this->factory->project->write('composer.json', <<<'JSON'
            {
              "name": "fixture/ctx",
              "type": "project",
              "require": { "acme/thing": "^1.0" },
              "suggest": { "acme/thing": "you already have this" }
            }
            JSON);
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
        ;

        $result  = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 suggest entry/entries duplicate a required package', $result->summary);
        self::assertCount(2, $this->composerInvocations(), 'nothing after the suggest check runs');
        self::assertStringContainsString('composer.json suggests a package it already requires', $printed);
        self::assertStringContainsString('acme/thing (require)', $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aDisallowedNormalizePluginFailsWithTheGuidanceBeforeNormalizing(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed('null')
        ;

        $result  = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(3, $this->composerInvocations(), 'normalize and dump-autoload never run');
        self::assertStringContainsString('ERROR: The ergebnis/composer-normalize plugin is not allowed in your composer.json', $printed);
        self::assertStringContainsString('"ergebnis/composer-normalize": true', $printed);
        self::assertStringContainsString('Then run: composer update nothing', $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aPendingNormalisationInAReadOnlyRunFailsWithTheWouldModifyGuidance(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed(self::COMPOSER_ALLOWED)
            ->willSucceed(self::PHP_VERSION)->willFail(1, 'composer.json is not normalized.')
        ;

        $result  = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: true)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(4, $this->composerInvocations(), 'dump-autoload does not run after a failed gate');
        self::assertStringContainsString('Composer Normalize: pending changes in a READ-ONLY run', $printed);
        self::assertStringContainsString('QA_READONLY=0 vendor/bin/qa -t com', $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aFailingWritableNormalizeFailsTheLane(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed(self::COMPOSER_ALLOWED)
            ->willSucceed(self::PHP_VERSION)->willFail(2, 'invalid json')
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: false)->build()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer normalize failed (exit 2)', $result->summary);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function aFailingAutoloaderDumpFailsTheLane(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed(self::COMPOSER_ALLOWED)
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willFail(1, 'Class Foo not found')
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer dump-autoload failed (exit 1)', $result->summary);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function withoutAnExplicitPathComposerIsLocatedOrDefaultsToItsBareName(): void
    {
        $this->factory->processes
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed()
            ->willSucceed(self::PHP_VERSION)->willSucceed('nope')
        ;

        new ComposerChecksTool()->run($this->factory->context());

        $script = $this->factory->processes->specs[1]->command[7];
        self::assertTrue('composer' === $script || str_ends_with($script, '/composer') || str_ends_with($script, '/composer.phar'), $script);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new ComposerChecksTool();

        self::assertSame('composerChecks', $tool->name());
        self::assertSame('phpqaci.composerChecks', $tool->identifier());
    }

    /** @return list<list<string>> the composer arguments of every composer invocation, in order (version probes skipped) */
    private function composerInvocations(): array
    {
        $composerSpecs = array_values(array_filter(
            $this->factory->processes->specs,
            static fn (ProcessSpecDto $spec): bool => \in_array(self::COMPOSER, $spec->command, true),
        ));

        return array_map(static fn (ProcessSpecDto $spec): array => \array_slice($spec->command, 9), $composerSpecs);
    }
}
