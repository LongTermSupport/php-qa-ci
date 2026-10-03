<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use Closure;
use FilesystemIterator;
use LTS\PHPQA\DefectRecord\DefectRecordReader;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ShippedTools;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Defence for the class "a lane that scans the checked paths drops the
 * project's withIgnoredPaths()".
 *
 * The setting is documented as the paths the scanning tools skip, and
 * docs/defence-before-fix-defaults.md tells a project to keep its fixtures out
 * of the sweep with it. A lane that scans the checked paths without it reports
 * the deliberate violations in those fixtures, and the project ends up
 * repeating the exclusion in each tool's own config, which is how this
 * repository's qaConfig/phpstan.neon came to carry `tests/assets` by hand.
 *
 * Every shipped lane is classified here: it scans the checked paths and so
 * must honour the setting, it does not scan them (with the reason), or it is a
 * known gap recorded in qaConfig/defect-record.neon for the Owner. A lane
 * added without a classification fails, so the next lane is classified when it
 * is written rather than when a consumer reports it. Each scanning lane is
 * then probed for behaviour, not for wiring:
 *
 * - REACHES: the ignored path is handed to the tool, in its argv, its
 *   environment or a config file the lane writes for it;
 * - FILTERS: the lane enumerates the files itself, and a file planted under
 *   the ignored path never reaches the tool;
 * - SKIPS: the lane checks in-process, and a violation planted under the
 *   ignored path does not fail it.
 *
 * Each probe also runs without the setting and requires the opposite result,
 * so a probe that would pass whatever the lane did is reported as vacuous.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class IgnoredPathsReachEveryScanningLaneTest extends TestCase
{
    /** The project-relative path every probe ignores. */
    private const string IGNORED = 'src/Legacy';

    /** A file under it that is a violation for every in-process lane. */
    private const string PLANTED = self::IGNORED . '/Planted.php';

    /** The tool the self-test lanes pretend to run. */
    private const string TOOL = '/tool.phar';

    /**
     * Lanes that scan the checked paths, or a tree inside them, and report
     * what they find there. Each must honour withIgnoredPaths().
     */
    private const array SCANS = [
        'rector'         => 'rewrites PHP under the checked paths',
        'phpCsFixer'     => 'rewrites PHP under the checked paths',
        'phpStrictTypes' => 'walks the checked paths for a missing strict_types declaration',
        'phpLint'        => 'lints every PHP file under the checked paths',
        'opcache'        => 'compiles every PHP file under the checked paths through OPcache',
        'psr4Validate'   => 'walks the composer.json autoload roots, which are src/ and tests/',
        'phpcpd'         => 'reports duplication across the checked paths',
        'phpstan'        => 'analyses the checked paths',
        'deadCode'       => 'analyses src/ and tests/ through phpstan.phar',
        'phpArkitect'    => 'checks the classes under the source dir against the architecture rules',
    ];

    /**
     * Lanes that scan the checked paths but cannot yet be told to skip a path.
     * Each is a deferred entry in qaConfig/defect-record.neon whose `found`
     * names the lane's source file, so the Owner sees it.
     */
    private const array KNOWN_GAPS = [
        'infection'   => "mutates infection.json's source directories, and Infection takes no exclusion on the command line",
    ];

    /** Lanes whose scope is not the checked paths, with the reason. */
    private const array DOES_NOT_SCAN = [
        'packageType'                => 'reads composer.json only',
        'configTemplateIgnoreList'   => "audits php-qa-ci's own configDefaults/ templates",
        'infectionConfigSourceDirs'  => 'reads infection.json only',
        'analysedPaths'              => 'audits PHP outside the checked paths; an ignored path is one of its accepted exclusions (AnalysedPathsAuditTest)',
        'versionPins'                => 'reads phpunit.xml, composerRequireChecker.json and the workflows',
        'changelog'                  => 'reads CHANGELOG.md and git history',
        'phpstanIgnoreJustification' => 'reads the phpstan.neon chain and the defect record',
        'sensitiveParameterUsage'    => 'asserts a usage exists somewhere in src/ and reports no file',
        'markdownLinks'              => 'reads README.md and docs/',
        'docsProse'                  => 'reads README.md and docs/',
        'branchNamePolicy'           => 'reads the git branch name',
        'composerChecks'             => 'reads composer.json and composer.lock',
        'composerRequireChecker'     => 'a dependency is real wherever the autoloader ships the code that uses it, ignored or not',
        'composerDependencyAnalyser' => 'a dependency is real wherever the autoloader ships the code that uses it, ignored or not',
        'twigCsFixer'                => 'scans the Twig directories, withTwigDirectories()',
        'twigLint'                   => 'scans the Twig directories, withTwigDirectories()',
        'yamlLint'                   => 'scans the YAML directories, withYamlDirectories()',
        'shellCheck'                 => 'scans git-tracked shell scripts; it honours the setting all the same (ShellCheckToolTest)',
        'phpunit'                    => 'executes the suite phpunit.xml defines and reports test outcomes, not files',
    ];

    /** Probe: the ignored path is handed to the tool. */
    private const string REACHES = 'reaches';

    /** Probe: a file under the ignored path never reaches the tool. */
    private const string FILTERS = 'filters';

    /** Probe: a violation under the ignored path does not fail the lane. */
    private const string SKIPS = 'skips';

    /** @var list<ContextFactory> */
    private array $factories = [];

    protected function tearDown(): void
    {
        foreach ($this->factories as $factory) {
            $factory->project->remove();
        }
    }

    #[Test]
    public function everyShippedLaneIsClassifiedExactlyOnce(): void
    {
        $classified = [...array_keys(self::SCANS), ...array_keys(self::KNOWN_GAPS), ...array_keys(self::DOES_NOT_SCAN)];
        $shipped    = array_keys(ShippedTools::all());

        self::assertSame([], array_values(array_diff($shipped, $classified)), 'unclassified lane: decide whether it scans the checked paths');
        self::assertSame([], array_values(array_diff($classified, $shipped)), 'classified lane that is not shipped');
        self::assertSame(array_values(array_unique($classified)), $classified, 'a lane is classified twice');
    }

    #[Test]
    public function everyScanningLaneHasAProbe(): void
    {
        $probed = array_map(static fn (array $probe): string => $probe[0], array_values(self::probes()));

        self::assertSame([], array_values(array_diff(array_keys(self::SCANS), $probed)));
    }

    #[Test]
    public function everyKnownGapIsInTheDefectRecord(): void
    {
        $root  = \dirname(__DIR__, 4);
        $found = array_map(static fn (DeferredDefectDto $entry): string => $entry->found, new DefectRecordReader()->read($root)->deferred);

        foreach (array_keys(self::KNOWN_GAPS) as $lane) {
            $file = (string)new ReflectionClass(ShippedTools::all()[$lane])->getFileName();
            self::assertContains(substr($file, \strlen($root) + 1), $found, $lane . ' is a known gap with no deferred entry naming its source file');
        }
    }

    /**
     * @param Closure(QaConfigBuilder): QaConfigBuilder $configure
     * @param Closure(FakeProcessRunner): void          $queue
     */
    #[Test]
    #[DataProvider('probes')]
    public function aScanningLaneHonoursTheIgnoredPaths(string $lane, string $kind, Closure $configure, Closure $queue): void
    {
        self::assertSame([], $this->probe(ShippedTools::all()[$lane], $kind, $configure, $queue));
    }

    /** @return array<string, array{string, string, Closure(QaConfigBuilder): QaConfigBuilder, Closure(FakeProcessRunner): void}> */
    public static function probes(): array
    {
        $asIs      = static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa;
        $succeeds  = static function (FakeProcessRunner $processes): void {
            for ($i = 0; $i < 20; ++$i) {
                $processes->willSucceed();
            }
        };
        $affected = static function (FakeProcessRunner $processes) use ($succeeds): void {
            $processes->willSucceed("8.5.10\n");
            $succeeds($processes);
        };
        $deadCode = static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints();

        return [
            'rector'         => ['rector', self::REACHES, $asIs, $succeeds],
            'phpCsFixer'     => ['phpCsFixer', self::REACHES, $asIs, $succeeds],
            'phpStrictTypes' => ['phpStrictTypes', self::SKIPS, $asIs, $succeeds],
            'phpLint'        => ['phpLint', self::REACHES, $asIs, $succeeds],
            'opcache'        => ['opcache', self::FILTERS, $asIs, $affected],
            'psr4Validate'   => ['psr4Validate', self::SKIPS, $asIs, $succeeds],
            'phpcpd'         => ['phpcpd', self::REACHES, $asIs, $succeeds],
            'phpstan'        => ['phpstan', self::REACHES, $asIs, $succeeds],
            'deadCode'       => ['deadCode', self::REACHES, $deadCode, $succeeds],
            'phpArkitect'    => ['phpArkitect', self::REACHES, $asIs, $succeeds],
        ];
    }

    #[Test]
    public function theReachesProbeFiresOnALaneThatHandsTheToolOnlyTheCheckedPaths(): void
    {
        $dropping = $this->lane(static function (ToolContext $context): ToolResultDto {
            $context->php->withoutXdebug(self::TOOL, $context->config->pathsToCheck, $context->config->paths->projectRoot);

            return ToolResultDto::passed();
        });

        self::assertSame(
            ['the ignored path ' . self::IGNORED . ' reached neither the argv, the environment nor a file the lane wrote'],
            $this->probe($dropping, self::REACHES, static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa, static fn (FakeProcessRunner $processes): FakeProcessRunner => $processes->willSucceed()),
        );
    }

    #[Test]
    public function theFiltersProbeFiresOnALaneThatHandsTheToolEveryFile(): void
    {
        $dropping = $this->lane(static function (ToolContext $context): ToolResultDto {
            $context->php->withoutXdebug(self::TOOL, [$context->config->paths->projectRoot . '/' . self::PLANTED], $context->config->paths->projectRoot);

            return ToolResultDto::passed();
        });

        self::assertSame(
            ['the planted file ' . self::PLANTED . ' under the ignored path reached the tool'],
            $this->probe($dropping, self::FILTERS, static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa, static fn (FakeProcessRunner $processes): FakeProcessRunner => $processes->willSucceed()),
        );
    }

    #[Test]
    public function theSkipsProbeFiresOnALaneThatReportsTheIgnoredFile(): void
    {
        $dropping = $this->lane(static fn (ToolContext $context): ToolResultDto => is_file($context->config->paths->projectRoot . '/' . self::PLANTED)
            ? ToolResultDto::failed('planted violation')
            : ToolResultDto::passed());

        self::assertSame(
            ['the violation planted under the ignored path failed the lane (Failed)'],
            $this->probe($dropping, self::SKIPS, static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa, static fn (FakeProcessRunner $processes): FakeProcessRunner => $processes),
        );
    }

    #[Test]
    public function aProbeThatWouldPassWhateverTheLaneDidIsReportedAsVacuous(): void
    {
        $alwaysNamesIt = $this->lane(static function (ToolContext $context): ToolResultDto {
            $context->php->withoutXdebug(self::TOOL, [self::IGNORED], $context->config->paths->projectRoot);

            return ToolResultDto::passed();
        });
        $neverFails = $this->lane(static fn (ToolContext $context): ToolResultDto => ToolResultDto::passed());

        $none = static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa;
        self::assertSame(
            ['vacuous: the ignored path ' . self::IGNORED . ' is named even when nothing is ignored'],
            $this->probe($alwaysNamesIt, self::REACHES, $none, static fn (FakeProcessRunner $processes): FakeProcessRunner => $processes->willSucceed()->willSucceed()),
        );
        self::assertSame(
            ['vacuous: the planted violation does not fail the lane even when nothing is ignored (Passed)'],
            $this->probe($neverFails, self::SKIPS, $none, static fn (FakeProcessRunner $processes): FakeProcessRunner => $processes),
        );
    }

    /**
     * Run the lane with the ignored path set and again without it.
     *
     * @param Closure(QaConfigBuilder): QaConfigBuilder $configure
     * @param Closure(FakeProcessRunner): mixed         $queue
     *
     * @return list<string> what is wrong; empty when the lane honours the setting
     */
    private function probe(ToolInterface $lane, string $kind, Closure $configure, Closure $queue): array
    {
        [$ignoredOutcome, $ignoredSeen]     = $this->drive($lane, $configure, $queue, true);
        [$controlOutcome, $controlSeen]     = $this->drive($lane, $configure, $queue, false);

        return match ($kind) {
            self::REACHES => array_values(array_filter([
                str_contains($ignoredSeen, self::IGNORED) ? null : 'the ignored path ' . self::IGNORED . ' reached neither the argv, the environment nor a file the lane wrote',
                str_contains($controlSeen, self::IGNORED) ? 'vacuous: the ignored path ' . self::IGNORED . ' is named even when nothing is ignored' : null,
            ])),
            self::FILTERS => array_values(array_filter([
                str_contains($ignoredSeen, self::PLANTED) ? 'the planted file ' . self::PLANTED . ' under the ignored path reached the tool' : null,
                str_contains($controlSeen, self::PLANTED) ? null : 'vacuous: the planted file does not reach the tool even when nothing is ignored',
            ])),
            default       => array_values(array_filter([
                ToolOutcomeEnum::Passed === $ignoredOutcome ? null : \sprintf('the violation planted under the ignored path failed the lane (%s)', $ignoredOutcome->name),
                ToolOutcomeEnum::Failed === $controlOutcome ? null : \sprintf('vacuous: the planted violation does not fail the lane even when nothing is ignored (%s)', $controlOutcome->name),
            ])),
        };
    }

    /**
     * @param Closure(QaConfigBuilder): QaConfigBuilder $configure
     * @param Closure(FakeProcessRunner): mixed         $queue
     *
     * @return array{ToolOutcomeEnum, string} the outcome, and everything the tool was handed
     */
    private function drive(ToolInterface $lane, Closure $configure, Closure $queue, bool $ignore): array
    {
        $factory           = ContextFactory::create();
        $this->factories[] = $factory;
        $factory->project->write('composer.json', "{\n  \"name\": \"fixture/ctx\",\n  \"type\": \"project\",\n  \"autoload\": {\"psr-4\": {\"Fixture\\\\\": \"src/\"}}\n}\n");
        $factory->project->write('src/Kept.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Kept\n{\n}\n");
        // Missing strict_types AND in the wrong namespace, so one file is a
        // violation for every in-process lane.
        $factory->project->write(self::PLANTED, "<?php\n\nnamespace Elsewhere;\n\nfinal class Planted\n{\n}\n");
        $queue($factory->processes);

        $builder = $configure($factory->builder());
        $config  = ($ignore ? $builder->withIgnoredPaths(self::IGNORED) : $builder)->build();
        $outcome = $lane->run($factory->context($config))->outcome;

        $seen = [];
        foreach ($factory->processes->specs as $spec) {
            $seen = [...$seen, ...$spec->command, ...array_values($spec->env)];
        }

        $varDir = $config->paths->varDir;
        if (is_dir($varDir)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($varDir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $seen[] = \Safe\file_get_contents($file->getPathname());
                }
            }
        }

        return [$outcome, implode("\n", $seen)];
    }

    /** @param Closure(ToolContext): ToolResultDto $run */
    private function lane(Closure $run): ToolInterface
    {
        return new readonly class($run) implements ToolInterface {
            /** @param Closure(ToolContext): ToolResultDto $run */
            public function __construct(private Closure $run)
            {
            }

            public function name(): string
            {
                return 'probe';
            }

            public function identifier(): string
            {
                return 'phpqaci.probe';
            }

            public function run(ToolContext $context): ToolResultDto
            {
                return ($this->run)($context);
            }
        };
    }
}
