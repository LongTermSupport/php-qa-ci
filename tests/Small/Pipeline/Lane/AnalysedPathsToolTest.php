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
        self::assertStringContainsString('2 PHP file(s)', $this->factory->output->fetch());
    }

    #[Test]
    public function phpInADirectoryNothingAnalysesFailsTheLaneNamingTheDirectoryAndBothRemedies(): void
    {
        $this->tracked(self::SRC_FILE, self::TEST_FILE, self::SERVICES);

        $result  = new AnalysedPathsTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(self::CONFIG_DIR, $printed);
        self::assertStringContainsString("withCheckedPaths('config')", $printed);
        self::assertStringContainsString("withUnanalysedPath('config', '", $printed);
        self::assertStringContainsString(AnalysedPathsTool::IDENTIFIER, $printed);
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
        $this->tracked(self::SRC_FILE, self::SERVICES);
        $config = $this->factory->builder()->withUnanalysedPath(self::CONFIG, self::REASON)->build();

        $result  = new AnalysedPathsTool()->run($this->factory->context($config));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::CONFIG_DIR, $printed);
        self::assertStringContainsString(self::REASON, $printed);
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
        self::assertStringContainsString('migrations', $printed);
        self::assertStringContainsString('no PHP file', $printed);
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
