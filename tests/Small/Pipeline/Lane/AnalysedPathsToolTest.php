<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\AnalysedPathsTool;
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
 * The analysedPaths lane, driven over a throwaway project: PHP the checked
 * paths do not reach fails the run until the project either analyses it or
 * declares, with a reason, that it is not analysed.
 *
 * The motivating case is a consumer's `config/services.php` carrying
 * `$_ENV['X'] ?? ''`: an enabled PHPStan rule forbids exactly that, and never
 * saw it, because only src/ and tests/ are analysed.
 *
 * @internal
 */
#[CoversClass(AnalysedPathsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\AnalysedPaths\AnalysedPathsAudit::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\AnalysedPaths\Dto\AnalysedPathsVerdictDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class AnalysedPathsToolTest extends TestCase
{
    private const string SRC_FILE = 'src/Service.php';

    private const string TEST_FILE = 'tests/ServiceTest.php';

    private const string SERVICES = 'config/services.php';

    private const string CONFIG = 'config';

    private const string CONFIG_DIR = 'config/';

    private const string REASON = 'Symfony container configuration, linted by lint:container';

    private const string PHP = "<?php\n\ndeclare(strict_types=1);\n";

    private const string ROOT_SCRIPT = 'rector.php';

    private const string IDENTIFIER_FOOTER = "\n🪪  phpqaci.analysedPaths  (vendor/bin/rule-doc phpqaci.analysedPaths)\n";

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        foreach ([self::SRC_FILE, self::TEST_FILE, self::SERVICES, 'vendor/acme/lib/A.php', self::ROOT_SCRIPT] as $file) {
            $this->factory->project->write($file, self::PHP);
        }

        $this->factory->project->write('README.md', "# fixture\n");
        $this->factory->project->write('bin/console', "#!/usr/bin/env php\n<?php\n");
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aProjectWhosePhpIsAllAnalysedPasses(): void
    {
        $this->tracked(self::SRC_FILE, self::TEST_FILE, 'README.md', 'bin/console');

        $result = new AnalysedPathsTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            "2 PHP file(s), every one under an analysed path (tests/, src/) or declared unanalysed\n",
            $this->factory->output->fetch(),
            'with nothing declared, a pass prints no declaration heading',
        );
    }

    /**
     * A source dir at the project root analyses the whole project, and a
     * checked path outside the project accounts for none of its files, even
     * one whose name starts with the project's own.
     */
    #[Test]
    public function aCheckedPathAtTheRootIsTheWholeProjectAndOneOutsideItIsDropped(): void
    {
        $this->tracked(self::SRC_FILE, self::SERVICES, self::ROOT_SCRIPT);
        $root   = $this->factory->project->path;
        $paths  = $this->factory->paths();
        $config = $this->factory->builder(paths: new \LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto(
            projectRoot: $root,
            libraryRoot: $paths->libraryRoot,
            binDir: $paths->binDir,
            srcDir: $root,
            testsDir: $root . '-tests',
            projectConfigDir: $paths->projectConfigDir,
            varDir: $paths->varDir,
            cacheDir: $paths->cacheDir,
            pharDir: $paths->pharDir,
            configDefaultsDir: $paths->configDefaultsDir,
        ))->build();

        $result = new AnalysedPathsTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            "3 PHP file(s), every one under an analysed path (the whole project) or declared unanalysed\n",
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function phpInADirectoryNothingAnalysesFailsTheLaneNamingTheDirectoryAndBothRemedies(): void
    {
        $this->tracked(self::SRC_FILE, self::TEST_FILE, self::SERVICES);

        $result = new AnalysedPathsTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHP that is neither analysed nor declared unanalysed, or a declaration that does not hold', $result->summary);
        self::assertSame(
            <<<'TEXT'
                PHP that no analysed path reaches and no declaration accounts for:
                  config/ (1 PHP file(s))
                      analyse it:   ->withCheckedPaths('config')
                      or declare:   ->withUnanalysedPath('config', '<why this PHP is not analysed>')

                PHPStan, Rector, PHP-CS-Fixer and the lint lanes read only the checked paths (tests/, src/), so no rule
                has seen these files. Classify each in qaConfig/qa.php: analyse it, which is right for
                code you maintain, or declare it unanalysed with the reason, which every run prints.

                TEXT . self::IDENTIFIER_FOOTER,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aRootLevelFileIsNamedByItself(): void
    {
        $this->tracked(self::SRC_FILE, self::ROOT_SCRIPT);

        $result = new AnalysedPathsTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("withUnanalysedPath('rector.php', '", $this->factory->output->fetch());
    }

    #[Test]
    public function aDirectoryDeclaredUnanalysedPassesAndTheRunPrintsTheReason(): void
    {
        $this->tracked(self::SRC_FILE, self::SERVICES, self::ROOT_SCRIPT);
        $config = $this->factory->builder()
            ->withUnanalysedPath(self::CONFIG, self::REASON)
            ->withUnanalysedPath(self::ROOT_SCRIPT, 'Rector configuration, read by Rector itself')
            ->build()
        ;

        $result = new AnalysedPathsTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            "3 PHP file(s), every one under an analysed path (tests/, src/) or declared unanalysed\n"
            . "Declared unanalysed in qaConfig/qa.php:\n"
            . '  ' . self::CONFIG_DIR . ' (1 PHP file(s)): ' . self::REASON . "\n"
            . "  rector.php (1 PHP file(s)): Rector configuration, read by Rector itself\n",
            $this->factory->output->fetch(),
            'a declared directory is shown with its trailing slash, a declared file without one',
        );
    }

    #[Test]
    public function aDirectoryTheProjectAddsToTheCheckedPathsPasses(): void
    {
        $this->tracked(self::SRC_FILE, self::SERVICES);
        $config = $this->factory->builder()->withCheckedPaths(self::CONFIG)->build();

        $result = new AnalysedPathsTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
    }

    #[Test]
    public function anIgnoredPathIsAlreadyADeclarationAndPasses(): void
    {
        $this->tracked(self::SRC_FILE, self::SERVICES);
        $config = $this->factory->builder()->withIgnoredPaths(self::CONFIG)->build();

        $result = new AnalysedPathsTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
    }

    #[Test]
    public function trackedVendorCodeIsUnanalysedByDefault(): void
    {
        $this->tracked(self::SRC_FILE, 'vendor/acme/lib/A.php');

        $result = new AnalysedPathsTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
    }

    #[Test]
    public function aDeclarationThatMatchesNoPhpFileFailsAsStale(): void
    {
        $this->tracked(self::SRC_FILE);
        $config = $this->factory->builder()->withUnanalysedPath('migrations', self::REASON)->build();

        $result  = new AnalysedPathsTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame(
            "withUnanalysedPath('migrations', ...) names a path with no PHP file: remove the declaration, or it would excuse PHP added there later unexamined.\n"
            . self::IDENTIFIER_FOOTER,
            $printed,
            'with nothing unclassified, the unclassified report and its explanation are not printed',
        );
    }

    #[Test]
    public function anIgnoredPathThatMatchesNothingFailsAsStale(): void
    {
        $this->tracked(self::SRC_FILE);
        $this->factory->project->write('tests/assets/fixture.twig', '');
        $config = $this->factory->builder()->withIgnoredPaths('src/Removed', 'tests/assets')->build();

        $result  = new AnalysedPathsTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("withIgnoredPaths('src/Removed') names a path where nothing exists", $printed);
        self::assertStringNotContainsString("withIgnoredPaths('tests/assets')", $printed);
        self::assertStringContainsString(AnalysedPathsTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function anIgnoredPathHoldingNoPhpIsNotStale(): void
    {
        $this->tracked(self::SRC_FILE);
        $this->factory->project->write('tests/assets/fixture.twig', '');
        $config = $this->factory->builder()->withIgnoredPaths('./tests/assets/')->build();

        self::assertSame(ToolOutcomeEnum::Passed, new AnalysedPathsTool()->run($this->factory->context($config))->outcome);
    }

    #[Test]
    public function anIgnoredFileThatExistsIsNotStale(): void
    {
        $this->tracked(self::SRC_FILE, self::ROOT_SCRIPT);
        $this->factory->project->write(self::ROOT_SCRIPT, "<?php\n");
        $config = $this->factory->builder()->withIgnoredPaths(self::ROOT_SCRIPT)->build();

        self::assertSame(ToolOutcomeEnum::Passed, new AnalysedPathsTool()->run($this->factory->context($config))->outcome);
    }

    #[Test]
    public function aDeclarationInsideAnAnalysedPathFailsAsContradicted(): void
    {
        $this->tracked(self::SRC_FILE);
        $config = $this->factory->builder()->withUnanalysedPath('src', self::REASON)->build();

        $result  = new AnalysedPathsTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('is analysed', $printed);
    }

    /** git lists what the index holds, so a file deleted from the working tree must not demand a declaration. */
    #[Test]
    public function aTrackedFileNoLongerOnDiskIsNotCounted(): void
    {
        $this->tracked(self::SRC_FILE, 'legacy/Gone.php');

        $result = new AnalysedPathsTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
    }

    #[Test]
    public function theFileListingIsOneQuietGitCallCoveringUntrackedButNotIgnoredFiles(): void
    {
        $this->tracked(self::SRC_FILE);

        new AnalysedPathsTool()->run($this->factory->context());

        $probe = $this->factory->processes->lastSpec();
        self::assertSame(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $probe->command);
        self::assertSame($this->factory->project->path, $probe->cwd);
        self::assertFalse($probe->streamOutput);
    }

    #[Test]
    public function aProjectThatIsNotAGitWorkTreeSkipsWithAReason(): void
    {
        $this->factory->processes->willFail(128, "fatal: not a git repository\n");

        $result = new AnalysedPathsTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('not a git work tree', $result->summary);
    }

    /** `-p` replaces the checked paths for one run, so everything else would read as unanalysed. */
    #[Test]
    public function aRunNarrowedToOnePathSkipsWithoutListingFiles(): void
    {
        $config = $this->factory->builder(specifiedPath: 'src')->build();

        $result = new AnalysedPathsTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new AnalysedPathsTool();

        self::assertSame('analysedPaths', $tool->name());
        self::assertSame('phpqaci.analysedPaths', $tool->identifier());
        self::assertSame(AnalysedPathsTool::IDENTIFIER, $tool->identifier());
    }

    private function tracked(string ...$files): void
    {
        $this->factory->processes->willSucceed(implode("\0", $files) . "\0");
    }
}
