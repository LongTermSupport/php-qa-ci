<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Arkitect;

use LTS\PHPQA\Arkitect\ArkitectRuleProbe;
use LTS\PHPQA\Arkitect\Dto\ProbeFiringDto;
use LTS\PHPQA\Arkitect\Dto\ProbeResultDto;
use LTS\PHPQA\Arkitect\Exception\ProbeFailedException;
use LTS\PHPQA\Arkitect\ProbeConfigRenderer;
use LTS\PHPQA\Pipeline\Agent\ArkitectJsonParser;
use LTS\PHPQA\Pipeline\Agent\ClassFileLocator;
use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\ParsedArchReportDto;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;
use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lane\PhpArkitect\ArkitectEnvironment;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The mechanism behind `bin/arkitect-rule <because> <path>`: run the project's
 * resolved PHPArkitect entry config over one fixture path instead of its own
 * class sets, and say whether the rule named by its `because` clause fired
 * there. A rule with zero instances in the project's code is proven this way,
 * on fixture code, before a green full run is trusted.
 *
 * The phar run is faked here; tests/Large/Arkitect/ArkitectRuleProbeTest.php
 * runs the real one.
 *
 * @internal
 */
#[CoversClass(ArkitectRuleProbe::class)]
#[CoversClass(ProbeResultDto::class)]
#[CoversClass(ProbeFiringDto::class)]
#[CoversClass(ProbeFailedException::class)]
#[UsesClass(ProbeConfigRenderer::class)]
#[UsesClass(ArkitectEnvironment::class)]
#[UsesClass(ArkitectJsonParser::class)]
#[UsesClass(ClassFileLocator::class)]
#[UsesClass(FileErrorDto::class)]
#[UsesClass(ParsedArchReportDto::class)]
#[UsesClass(UnreadableReportException::class)]
#[UsesClass(ConfigPathResolver::class)]
#[UsesClass(ProjectPathsDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(PhpInvoker::class)]
#[Small]
final class ArkitectRuleProbeTest extends TestCase
{
    private const string INTERFACE_BECAUSE = 'an Interface suffix makes the symbol kind obvious at every use site';

    private const string ENUM_BECAUSE = 'an Enum suffix makes the symbol kind obvious at every use site';

    private const string FIXTURE_DIR = 'tests/Fixtures/Arkitect';

    private const string GATEWAY_FILE = self::FIXTURE_DIR . '/Gateway.php';

    private const string LEDGER_FILE = self::FIXTURE_DIR . '/Ledger.php';

    private const string GATEWAY_FQCN = 'Probe\Fixtures\Gateway';

    private const string LEDGER_FQCN = 'Probe\Fixtures\Ledger';

    private const string AUTOLOAD = '/project/vendor/autoload.php';

    private const string NOT_FIRED = 'did not fire';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(self::GATEWAY_FILE, "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe\\Fixtures;\n\ninterface Gateway\n{\n}\n");
        $this->factory->project->write(self::LEDGER_FILE, "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe\\Fixtures;\n\ninterface Ledger\n{\n}\n");
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function itFiresWhenAViolationCarriesTheBecauseClause(): void
    {
        $this->factory->processes->willFail(1, $this->pharOutput([
            self::GATEWAY_FQCN => ['should have a name that matches *Interface because ' . self::INTERFACE_BECAUSE],
        ]));

        $result = $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::FIXTURE_DIR));

        self::assertTrue($result->fired());
        self::assertCount(1, $result->firings);
        self::assertSame(self::GATEWAY_FQCN, $result->firings[0]->fqcn);
        self::assertSame(self::GATEWAY_FILE, $result->firings[0]->file);
    }

    #[Test]
    public function aViolationOfAnotherRuleIsNotAFiring(): void
    {
        $this->factory->processes->willFail(1, $this->pharOutput([
            self::GATEWAY_FQCN => ['should have a name that matches *Interface because ' . self::INTERFACE_BECAUSE],
        ]));

        $result = $this->probe()->probe(self::ENUM_BECAUSE, $this->path(self::FIXTURE_DIR));

        self::assertFalse($result->fired());
        self::assertStringContainsString(self::NOT_FIRED, $result->render());
    }

    #[Test]
    public function aCleanRunIsNotAFiring(): void
    {
        $this->factory->processes->willSucceed($this->pharOutput([]));

        $result = $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::FIXTURE_DIR));

        self::assertFalse($result->fired());
    }

    /**
     * Proving a rule takes a pair: it fires on the violating subject and stays
     * silent on the conforming one. The two usually share a directory, so a
     * file path must count only the classes that file declares.
     */
    #[Test]
    public function aFilePathCountsOnlyTheClassesThatFileDeclares(): void
    {
        $this->factory->processes->willFail(1, $this->pharOutput([
            self::GATEWAY_FQCN => ['should have a name that matches *Interface because ' . self::INTERFACE_BECAUSE],
            self::LEDGER_FQCN  => ['should have a name that matches *Interface because ' . self::INTERFACE_BECAUSE],
        ]));

        $result = $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::LEDGER_FILE));

        self::assertCount(1, $result->firings);
        self::assertSame(self::LEDGER_FQCN, $result->firings[0]->fqcn);
    }

    #[Test]
    public function aFilePathProbesItsDirectory(): void
    {
        $this->factory->processes->willSucceed($this->pharOutput([]));

        $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::LEDGER_FILE));

        self::assertStringContainsString(
            var_export($this->path(self::FIXTURE_DIR), true),
            $this->factory->project->read('var/qa/' . ArkitectRuleProbe::CONFIG_DIR . '/' . ArkitectRuleProbe::CONFIG_FILE),
        );
    }

    /**
     * The probe answers for the rules the pipeline runs, so it runs the same
     * phar, the same entry config and the same environment the lane hands it,
     * with no baseline. Only the class sets are swapped for the probed path.
     */
    #[Test]
    public function itRunsTheResolvedEntryConfigOverTheProbedPathWithTheLanesEnvironment(): void
    {
        $projectEntry = $this->factory->project->write('qaConfig/phparkitect.php', "<?php\n");
        $this->factory->processes->willSucceed($this->pharOutput([]));

        $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::FIXTURE_DIR));

        $spec       = $this->factory->processes->lastSpec();
        $configFile = $this->factory->project->path . '/var/qa/' . ArkitectRuleProbe::CONFIG_DIR . '/' . ArkitectRuleProbe::CONFIG_FILE;
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertContains(\dirname(__DIR__, 3) . '/vendor-phar/phparkitect.phar', $spec->command);
        self::assertContains('check', $spec->command);
        self::assertContains('--config=' . $configFile, $spec->command);
        self::assertContains('--autoload=' . self::AUTOLOAD, $spec->command);
        self::assertContains('--skip-baseline', $spec->command);
        self::assertContains('--format=json', $spec->command);
        self::assertSame($this->factory->project->path . '/src', $spec->env['PHPQACI_ARKITECT_SRC_DIR']);
        self::assertSame(\dirname(__DIR__, 3) . '/configDefaults/generic/phparkitect-rules-default.php', $spec->env['PHPQACI_ARKITECT_RULES_DEFAULT']);

        $config = $this->factory->project->read('var/qa/' . ArkitectRuleProbe::CONFIG_DIR . '/' . ArkitectRuleProbe::CONFIG_FILE);
        self::assertStringContainsString(var_export($projectEntry, true), $config);
        self::assertStringContainsString(var_export($this->path(self::FIXTURE_DIR), true), $config);
    }

    #[Test]
    public function aPharCrashIsAFailureNotAVerdict(): void
    {
        $this->factory->processes->willFail(255, 'PHP Fatal error: the entry config threw');

        $this->expectException(ProbeFailedException::class);
        $this->expectExceptionMessage('the entry config threw');

        $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::FIXTURE_DIR));
    }

    #[Test]
    public function outputWithNoReportIsAFailureNotAVerdict(): void
    {
        $this->factory->processes->willFail(1, 'something that is not a report');

        $this->expectException(ProbeFailedException::class);

        $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::FIXTURE_DIR));
    }

    #[Test]
    public function aMissingPathIsAFailureAndRunsNothing(): void
    {
        try {
            $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path('tests/Fixtures/Nowhere.php'));
            self::fail('A missing path must not be probed');
        } catch (ProbeFailedException $probeFailedException) {
            self::assertStringContainsString('Nowhere.php', $probeFailedException->getMessage());
        }

        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function anEmptyBecauseClauseIsRefused(): void
    {
        $this->expectException(ProbeFailedException::class);

        $this->probe()->probe('  ', $this->path(self::FIXTURE_DIR));
    }

    #[Test]
    public function aFiringRendersTheClassItsFileAndTheMessage(): void
    {
        $this->factory->processes->willFail(1, $this->pharOutput([
            self::GATEWAY_FQCN => ['should have a name that matches *Interface because ' . self::INTERFACE_BECAUSE],
        ]));

        $rendered = $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::FIXTURE_DIR))->render();

        self::assertStringContainsString('FIRED (1)', $rendered);
        self::assertStringContainsString(self::GATEWAY_FQCN . ' (' . self::GATEWAY_FILE . ')', $rendered);
        self::assertStringContainsString('should have a name that matches *Interface', $rendered);
    }

    /**
     * Not firing is also what a mistyped clause looks like, so the verdict says
     * so and names the entry config the rule was looked for in.
     */
    #[Test]
    public function aMissRendersTheEntryConfigItLookedIn(): void
    {
        $this->factory->processes->willSucceed($this->pharOutput([]));

        $rendered = $this->probe()->probe(self::INTERFACE_BECAUSE, $this->path(self::FIXTURE_DIR))->render();

        self::assertStringContainsString(self::NOT_FIRED, $rendered);
        self::assertStringContainsString('configDefaults/generic/phparkitect.php', $rendered);
    }

    private function probe(): ArkitectRuleProbe
    {
        $paths = $this->factory->paths();

        return new ArkitectRuleProbe(
            $paths,
            new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, PlatformEnum::Generic),
            new PhpInvoker($this->factory->processes, '/usr/bin/php', '1G', $paths->varDir),
            self::AUTOLOAD,
        );
    }

    private function path(string $relative): string
    {
        return $this->factory->project->path . '/' . $relative;
    }

    /**
     * What the phar prints with --format=json: a banner and a progress bar,
     * the report, then a verdict line.
     *
     * @param array<string, list<string>> $violations error messages keyed by class
     */
    private function pharOutput(array $violations): string
    {
        $details = [];
        $total   = 0;
        foreach ($violations as $fqcn => $messages) {
            $details[$fqcn] = array_map(static fn (string $message): array => ['error' => $message], $messages);
            $total += \count($messages);
        }

        return "PHPArkitect 1.3.1.0\n\n 1/1 [====] 100%\n\n"
            . \Safe\json_encode(['totalViolations' => $total, 'details' => [] === $details ? [] : $details], \JSON_PRETTY_PRINT)
            . "\n" . (0 === $total ? 'NO VIOLATIONS' : $total . ' violations detected!') . "\n";
    }
}
