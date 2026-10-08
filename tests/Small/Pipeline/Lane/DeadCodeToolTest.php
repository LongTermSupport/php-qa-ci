<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\DeadCode\DetectorUnpacker;
use LTS\PHPQA\Pipeline\Lane\DeadCodeTool;
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
#[CoversClass(DeadCodeTool::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ApiSurfaceEnforcementModeEnum::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ProjectComposerTypeReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\IgnoredPaths::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\ExcludePathsNeon::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(DetectorUnpacker::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\TemporaryDirectory::class)]
#[Small]
final class DeadCodeToolTest extends TestCase
{
    private const string WRAPPER = 'var/qa/' . DeadCodeTool::LOG_DIR . '/' . DeadCodeTool::WRAPPER_NEON;

    private const string COMPOSER_JSON = 'composer.json';

    private const string API_CLASS = "<?php\n\n/** @api */\nfinal class Thing {}\n";

    private const string PROJECT_TYPE = '{"type": "project"}';

    private const string THING = 'src/Thing.php';

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
    public function itIsSkippedUntilAProjectOptsIn(): void
    {
        $result = new DeadCodeTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('off; withDeadCodeDetection(true) in qaConfig/qa.php enables it', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aCleanRunWritesTheWrapperNeonWithThePharTheTestsExcluderAndTheEntryPoints(): void
    {
        $this->factory->processes->willSucceed("[OK] No errors\n");
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder(ci: true)
            ->withDeadCodeDetection(true)
            ->withDeadCodeEntryPoints('bin/console', '/abs/bin/tool')
            ->build()
        ;
        $paths = $config->paths;

        $result = new DeadCodeTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $wrapper  = $this->factory->project->read(self::WRAPPER);
        $detector = new DetectorUnpacker()->unpack($paths->pharDir . '/dead-code-detector.phar', $paths->cacheDir);
        self::assertStringContainsString("includes:\n    - " . \dirname(__DIR__, 4) . "/configDefaults/generic/phpstan.neon\n    - " . $detector . '/' . DetectorUnpacker::RULES_NEON . "\n", $wrapper);
        self::assertStringContainsString("    paths:\n        - " . $paths->srcDir . "\n        - " . $paths->testsDir . "\n        - " . $paths->projectRoot . "/bin/console\n        - /abs/bin/tool\n", $wrapper);
        self::assertStringContainsString("    shipmonkDeadCode:\n        usageExcluders:\n            tests:\n                enabled: true\n", $wrapper);
        self::assertStringContainsString("    parallel:\n        maximumNumberOfProcesses: 2\n", $wrapper);
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-f', $paths->pharDir . '/phpstan.phar', '--', 'analyse', '-c', $paths->projectRoot . '/' . self::WRAPPER, '--autoload-file', $detector . '/' . DetectorUnpacker::AUTOLOAD, '--no-progress'],
            $this->factory->processes->lastSpec()->command,
        );
        // With Turbo, PHPStan forks its workers, and only phpstan.phar's reads are guarded across
        // the fork: a second PHAR is read through one shared descriptor and its source garbles.
        self::assertStringNotContainsString('phar://', $wrapper, 'the detector reaches PHPStan as plain files');
        self::assertStringNotContainsString('phar://', implode(' ', $this->factory->processes->lastSpec()->command), 'the detector reaches PHPStan as plain files');
        self::assertSame($paths->projectRoot, $this->factory->processes->lastSpec()->cwd);
        self::assertStringNotContainsString(DeadCodeTool::IDENTIFIER, $this->factory->output->fetch());
        self::assertStringNotContainsString('excludePaths', $wrapper, 'nothing ignored, nothing excluded');
    }

    #[Test]
    public function theIgnoredPathsAreExcludedExactlyAsThePhpstanLaneExcludesThem(): void
    {
        $this->factory->processes->willSucceed("[OK] No errors\n");
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $root   = $this->factory->project->path;
        $config = $this->factory->builder()
            ->withDeadCodeDetection(true)
            ->withoutDeadCodeEntryPoints()
            ->withIgnoredPaths('tests/assets')
            ->build()
        ;

        new DeadCodeTool()->run($this->factory->context($config));

        self::assertStringContainsString(
            "    excludePaths:\n        analyse:\n            - '" . $root . "/tests/assets' (?)\n    shipmonkDeadCode:\n",
            $this->factory->project->read(self::WRAPPER),
        );
    }

    #[Test]
    public function aLibraryWithNoApiTagFailsFastBeforeAnythingRuns(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, '{"type": "library"}');
        $this->factory->project->write(self::THING, "<?php\n\n/** @internal */\nfinal class Thing {}\n");

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result  = new DeadCodeTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('no @api tag in src/; a library needs its public surface declared before dead-code detection means anything', $result->summary);
        self::assertStringContainsString('every public class-like as @api or @internal', $printed);
        self::assertStringContainsString(DeadCodeTool::IDENTIFIER, $printed);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aLibraryWithAnApiTagRunsAndAProjectNeverNeedsOne(): void
    {
        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, '{"type": "library"}');
        $this->factory->project->write(self::THING, self::API_CLASS);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        self::assertSame(ToolOutcomeEnum::Passed, new DeadCodeTool()->run($this->factory->context($config))->outcome);

        $this->factory->processes->willSucceed();
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);
        $this->factory->project->write(self::THING, "<?php\n\nfinal class Thing {}\n");

        self::assertSame(ToolOutcomeEnum::Passed, new DeadCodeTool()->run($this->factory->context($config))->outcome);
    }

    #[Test]
    public function findingsFailTheLaneWithTheIdentifier(): void
    {
        $this->factory->processes->willFail(1, 'Unused Foo::bar');
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result  = new DeadCodeTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('dead code found', $result->summary);
        self::assertStringContainsString('withDeadCodeEntryPoints', $printed);
        self::assertStringContainsString(DeadCodeTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function anExitAboveOneIsACrash(): void
    {
        $this->factory->processes->willFail(255, 'Fatal');
        $this->factory->project->write(self::COMPOSER_JSON, self::PROJECT_TYPE);

        $config = $this->factory->builder()->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints()->build();

        $result = new DeadCodeTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('PHPStan crashed (exit 255)', $result->summary);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new DeadCodeTool();

        self::assertSame('deadCode', $tool->name());
        self::assertSame('phpqaci.deadCode', $tool->identifier());
    }
}
