<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Infection;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Lane\Infection\AutoloadIncludedFilesProbe;
use LTS\PHPQA\Pipeline\Lane\Infection\PreloadedSourceFinder;
use LTS\PHPQA\Pipeline\Lane\InfectionTool;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The infection lane's preloaded-source check (#155) with the real
 * bin/autoload-included-files: an autoload `files` entry that loads a source
 * file under PHPUnit is found, through the probe and through the lane, and
 * this repository's own autoloader is held to the same check.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PreloadedSourceTest extends TestCase
{
    private const string LIBRARY = __DIR__ . '/../../..';

    private const string FIXTURE = __DIR__ . '/../../assets/infection/preloadedSource';

    private const string PRELOADED = '/src/Preloaded.php';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    /** The entry runs only with PHPUNIT_COMPOSER_INSTALL defined, so finding it proves the probe loads as PHPUnit does. */
    #[Test]
    public function theProbeSeesTheSourceFileAFilesEntryLoadsUnderPhpunit(): void
    {
        $fixture = \Safe\realpath(self::FIXTURE);

        $included = new AutoloadIncludedFilesProbe()->includedFiles($this->realContext(), $fixture . '/autoload.php');

        self::assertIsArray($included, \is_string($included) ? $included : '');
        self::assertContains($fixture . self::PRELOADED, $included);
        self::assertSame(
            [$fixture . self::PRELOADED],
            new PreloadedSourceFinder()->find([$fixture . '/src'], [], $included),
        );
    }

    /** Through the lane, with the probe it ships: the run crashes before any coverage is generated. */
    #[Test]
    public function theLaneCrashesOnAProjectWhoseFilesEntryLoadsASourceFile(): void
    {
        $project = $this->factory->project;
        $project->write('vendor/autoload.php', "<?php\n\nif (\\defined('PHPUNIT_COMPOSER_INSTALL')) {\n    require_once __DIR__ . '/../src/Preloaded.php';\n}\n");
        $project->write('src/Preloaded.php', \Safe\file_get_contents(self::FIXTURE . self::PRELOADED));
        $project->write('qaConfig/infection.json', '{"source": {"directories": ["../src"]}}');

        $result  = new InfectionTool()->run($this->realContext(['infectionDiffBase' => 'full']));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, $printed);
        self::assertStringContainsString("\n           " . \Safe\realpath($project->path . self::PRELOADED) . "\n", $printed);
        self::assertStringNotContainsString('generating fresh coverage', $printed);
    }

    /**
     * The instance #155 was found on: tests/Support/TempLeak/claim-before-bootstrap.php, an
     * autoload-dev files entry, reached TempLeakDirectory::claim(), which used ProcessTree, so no
     * mutant of ProcessTree could be killed. The autoloader now loads nothing Infection mutates.
     */
    #[Test]
    public function thisRepositorysOwnAutoloaderLoadsNothingInfectionMutates(): void
    {
        $library = \Safe\realpath(self::LIBRARY);

        $included = new AutoloadIncludedFilesProbe()->includedFiles($this->realContext(), $library . '/vendor/autoload.php');
        self::assertIsArray($included, \is_string($included) ? $included : '');

        $config = \Safe\json_decode(\Safe\file_get_contents($library . '/qaConfig/infection.json'), true);
        self::assertIsArray($config);
        self::assertIsArray($config['source'] ?? null);
        $source = $config['source'];
        self::assertIsList($source['directories'] ?? null);
        self::assertIsList($source['excludes'] ?? null);
        self::assertContainsOnlyString($source['directories']);
        self::assertContainsOnlyString($source['excludes']);

        self::assertNotContains($library . '/src/Pipeline/Process/ProcessTree.php', $included);
        self::assertSame(
            [],
            new PreloadedSourceFinder()->find(
                array_map(static fn (string $directory): string => $library . '/qaConfig/' . $directory, $source['directories']),
                $source['excludes'],
                $included,
            ),
        );
    }

    /**
     * A context whose processes really run, over the fixture project.
     *
     * @param array<string, string> $env
     */
    private function realContext(array $env = []): ToolContext
    {
        $config    = $this->factory->builder(env: $env)->build();
        $paths     = $config->paths;
        $processes = new SymfonyProcessRunner(new BufferedOutput());

        return new ToolContext(
            config: $config,
            configPaths: new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, $config->platform),
            processes: $processes,
            php: new PhpInvoker($processes, \PHP_BINARY, '1G', $paths->varDir),
            logs: new LogArchiver($this->factory->output),
            output: $this->factory->output,
            stdout: $this->factory->stdout,
        );
    }
}
