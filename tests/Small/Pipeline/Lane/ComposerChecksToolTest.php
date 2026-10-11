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
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecks\RedundantSuggestDetector::class)]
#[UsesClass(\LTS\PHPQA\Helper::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class ComposerChecksToolTest extends TestCase
{
    private const string DIAGNOSE = 'diagnose';

    private const string DRY_RUN = '--dry-run';

    private const string COMPOSER = '/opt/fixture/bin/composer';

    private const string NORMALIZE_PHAR = 'composer-normalize.phar';

    private const string COMPOSER_JSON = '/composer.json';

    private const string DIAGNOSE_HEADING = "\n\nChecking Composer Issues - Fix Any Red Stuff (wont fail the process)\n---------------------\n\n";

    private const string AUDIT_HEADING = "\n\nAuditing Dependencies For Known Advisories\n---------------------\n\n";

    private const string DUMP_HEADING = "\n\nDumping Composer Autoloader\n---------------------\n\n";

    private const string IDENTIFIER_FOOTER = "\n🪪  phpqaci.composerChecks  (vendor/bin/rule-doc phpqaci.composerChecks)\n";

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
    public function aReadOnlyRunDiagnosesAuditsDryRunsTheNormalizePharAndDumpsTheAutoloader(): void
    {
        $this->factory->processes
            ->willSucceed('diagnose ok')
            ->willSucceed('No security vulnerability advisories found.')
            ->willSucceed('composer.json is already normalized.')
            ->willSucceed('Generated autoload files')
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: true)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            [
                [self::DIAGNOSE],
                ['audit', '--locked', '--abandoned=report', '--no-interaction'],
                ['dump-autoload'],
            ],
            $this->composerInvocations(),
        );
        self::assertSame([self::DRY_RUN, $this->factory->project->path . self::COMPOSER_JSON], $this->normalizeArguments());
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Auditing Dependencies For Known Advisories', $printed);
        self::assertStringContainsString('Checking Composer Normalisation (read-only)', $printed);
        self::assertStringContainsString('Dumping Composer Autoloader', $printed);
        self::assertStringNotContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aWritableRunAppliesNormalizeThroughThePhar(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willSucceed()
            ->willSucceed('Successfully normalized')
            ->willSucceed()
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: false)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([$this->factory->project->path . self::COMPOSER_JSON], $this->normalizeArguments());
        self::assertStringContainsString('Running Composer Normalise', $this->factory->output->fetch());
    }

    /**
     * The PHAR bundles Composer and the normalize command, so no plugin has to be
     * installed or allow-listed in the consumer: the lane never probes allow-plugins.
     */
    #[Test]
    public function theNormalizePharIsRunFromTheLibrarysPharDirWithoutAnyPluginProbe(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willSucceed()
            ->willSucceed()
            ->willSucceed()
        ;

        new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        $normalize = $this->factory->processes->specs[2];
        self::assertSame($this->factory->paths()->pharDir . '/' . self::NORMALIZE_PHAR, $normalize->command[4]);
        self::assertSame($this->factory->project->path, $normalize->cwd);
        self::assertTrue($normalize->streamOutput);
        foreach ($this->factory->processes->specs as $spec) {
            self::assertNotContains('config', $spec->command, 'no allow-plugins probe runs');
        }
    }

    #[Test]
    public function everyComposerCallGoesThroughPhpWithoutXdebugFromTheProjectRoot(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willSucceed()
            ->willSucceed()
            ->willSucceed()
        ;
        $root = $this->factory->project->path;

        new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        $first = $this->factory->processes->specs[0];
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-f', self::COMPOSER, '--', self::DIAGNOSE],
            $first->command,
        );
        self::assertSame($root, $first->cwd);
    }

    #[Test]
    public function aFailingDiagnoseIsInformationalOnly(): void
    {
        $this->factory->processes
            ->willFail(1, 'Checking platform settings: FAIL')
            ->willSucceed()
            ->willSucceed()
            ->willSucceed()
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('composer diagnose reported issues (shown above) — informational only, not failing the pipeline.', $this->factory->output->fetch());
    }

    #[Test]
    public function aKnownAdvisoryFailsTheLaneWithTheRemediationBeforeNormalizing(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willFail(1, 'Found 1 security vulnerability advisory affecting 1 package')
        ;

        $result  = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer audit found advisories or could not run (exit 1)', $result->summary);
        self::assertCount(2, $this->factory->processes->specs, 'nothing after the audit runs');
        self::assertStringContainsString('composer update <vendor/package> --with-all-dependencies', $printed);
        self::assertStringContainsString('config.audit.ignore', $printed);
        self::assertStringContainsString('withComposerAudit(false)', $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function theAuditIsSkippedWithANoteWhenDisabledForTheProject(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willSucceed()
            ->willSucceed()
        ;

        $config = $this->factory->builder()->withComposerAudit(false)->build();
        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([[self::DIAGNOSE], ['dump-autoload']], $this->composerInvocations());
        self::assertSame([self::DRY_RUN, $this->factory->project->path . self::COMPOSER_JSON], $this->normalizeArguments());
        self::assertStringContainsString('composer audit disabled for this project', $this->factory->output->fetch());
    }

    #[Test]
    public function aSuggestEntryNamingAnAlreadyRequiredPackageFailsBeforeNormalizing(): void
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
            ->willSucceed()
            ->willSucceed()
        ;

        $result  = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 suggest entry/entries duplicate a required package', $result->summary);
        self::assertCount(2, $this->factory->processes->specs, 'nothing after the suggest check runs');
        self::assertStringContainsString('composer.json suggests a package it already requires', $printed);
        self::assertStringContainsString('acme/thing (require)', $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aPendingNormalisationInAReadOnlyRunFailsWithTheWouldModifyGuidance(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willSucceed()
            ->willFail(1, 'composer.json is not normalized.')
        ;

        $result  = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: true)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertCount(3, $this->factory->processes->specs, 'dump-autoload does not run after a failed gate');
        self::assertStringContainsString('Composer Normalize: pending changes in a READ-ONLY run', $printed);
        self::assertStringContainsString('QA_READONLY=0 vendor/bin/qa -t com', $printed);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aFailingWritableNormalizeFailsTheLane(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willSucceed()
            ->willFail(2, 'invalid json')
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: false)->build()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer-normalize failed (exit 2)', $result->summary);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function aFailingAutoloaderDumpFailsTheLane(): void
    {
        $this->factory->processes
            ->willSucceed()
            ->willSucceed()
            ->willSucceed()
            ->willFail(1, 'Class Foo not found')
        ;

        $result = new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer dump-autoload failed (exit 1)', $result->summary);
        self::assertStringContainsString(ComposerChecksTool::IDENTIFIER, $this->factory->output->fetch());
    }

    /** The lane's own output on a clean read-only run is exactly its four step headings. */
    #[Test]
    public function aCleanReadOnlyRunPrintsExactlyItsStepHeadings(): void
    {
        $this->factory->processes->willSucceed()->willSucceed()->willSucceed()->willSucceed();

        new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: true)->build()));

        self::assertSame(
            self::DIAGNOSE_HEADING . self::AUDIT_HEADING . self::heading('Checking Composer Normalisation (read-only)') . self::DUMP_HEADING,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aCleanWritableRunPrintsExactlyItsStepHeadings(): void
    {
        $this->factory->processes->willSucceed()->willSucceed()->willSucceed()->willSucceed();

        new ComposerChecksTool(self::COMPOSER)->run($this->factory->context($this->factory->builder(readOnly: false)->build()));

        self::assertSame(
            self::DIAGNOSE_HEADING . self::AUDIT_HEADING . self::heading('Running Composer Normalise') . self::DUMP_HEADING,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aFailedAuditPrintsTheWholeRemediationAndTheIdentifier(): void
    {
        $this->factory->processes->willSucceed()->willFail(1);

        new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        self::assertSame(
            self::DIAGNOSE_HEADING . self::AUDIT_HEADING . <<<'TEXT'

                composer audit did not pass (output above). Either a locked dependency has a
                published security advisory, or the advisory database could not be reached.

                TO FIX
                  - Update the affected package to a patched release and commit composer.lock:
                        composer update <vendor/package> --with-all-dependencies
                  - If no patched release exists yet, pin an explicit exception in composer.json
                    under config.audit.ignore with the advisory id and a comment saying why.
                  - An abandoned package is reported, not failed: plan its replacement.
                  - Offline builds: ->withComposerAudit(false) in qaConfig/qa.php, or
                    useComposerAudit=0 for one run.

                TEXT . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aRedundantSuggestPrintsEachEntryAndTheWholeRemediation(): void
    {
        $this->factory->project->write('composer.json', <<<'JSON'
            {
              "name": "fixture/ctx",
              "type": "project",
              "require": { "acme/thing": "^1.0" },
              "require-dev": { "acme/other": "^2.0" },
              "suggest": { "acme/thing": "already required", "acme/other": "already a dev requirement" }
            }
            JSON);
        $this->factory->processes->willSucceed()->willSucceed();

        new ComposerChecksTool(self::COMPOSER)->run($this->factory->context());

        self::assertSame(
            self::DIAGNOSE_HEADING . self::AUDIT_HEADING . <<<'TEXT'

                ERROR: composer.json suggests a package it already requires:

                  - acme/thing (require)
                  - acme/other (require-dev)

                A suggestion is advice to install something the consumer does not already
                get. Advice they cannot act on is noise, and noise in that block trains the
                reader to skip the rest of it.

                TO FIX: delete the entry from "suggest". If the package should be optional
                rather than required, move it out of "require"/"require-dev" instead.

                TEXT . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    /** Composer is looked up on PATH when no path is given, and the path found is the one run. */
    #[Test]
    public function withoutAnExplicitPathTheComposerFoundOnPathIsRun(): void
    {
        $this->factory->project->write('path-bin/composer', "#!/bin/sh\n");
        $bin = $this->factory->project->path . '/path-bin';
        chmod($bin . '/composer', 0o755);
        $this->factory->processes->willSucceed()->willFail(1);

        $this->runWithPath($bin);

        self::assertSame($bin . '/composer', $this->factory->processes->specs[0]->command[4]);
    }

    #[Test]
    public function withoutAnExplicitPathAndNoComposerOnPathTheBareNameIsRun(): void
    {
        $this->factory->processes->willSucceed()->willFail(1);

        $this->runWithPath($this->factory->project->path);

        self::assertSame('composer', $this->factory->processes->specs[0]->command[4]);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new ComposerChecksTool();

        self::assertSame('composerChecks', $tool->name());
        self::assertSame('phpqaci.composerChecks', $tool->identifier());
    }

    /** Run the lane with no composer path given and PATH set to the one directory, restoring PATH after. */
    private function runWithPath(string $directory): void
    {
        $path = getenv('PATH');
        putenv('PATH=' . $directory);
        try {
            new ComposerChecksTool()->run($this->factory->context());
        } finally {
            putenv(false === $path ? 'PATH' : 'PATH=' . $path);
        }
    }

    /** A step heading as the lane prints it: two blank lines, the title, the rule and a blank line. */
    private static function heading(string $title): string
    {
        return "\n\n" . $title . "\n---------------------\n\n";
    }

    /** @return list<list<string>> the composer arguments of every composer invocation, in order */
    private function composerInvocations(): array
    {
        $composerSpecs = array_values(array_filter(
            $this->factory->processes->specs,
            static fn (ProcessSpecDto $spec): bool => \in_array(self::COMPOSER, $spec->command, true),
        ));

        return array_map(static fn (ProcessSpecDto $spec): array => \array_slice($spec->command, 6), $composerSpecs);
    }

    /** @return list<string> the arguments given to the composer-normalize PHAR */
    private function normalizeArguments(): array
    {
        $pharSpecs = array_values(array_filter(
            $this->factory->processes->specs,
            static fn (ProcessSpecDto $spec): bool => isset($spec->command[4]) && str_ends_with($spec->command[4], self::NORMALIZE_PHAR),
        ));
        self::assertCount(1, $pharSpecs, 'the normalize PHAR runs exactly once');

        return \array_slice($pharSpecs[0]->command, 6);
    }
}
