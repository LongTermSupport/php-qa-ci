<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\ComposerDependencyAnalyserTool;
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
#[CoversClass(ComposerDependencyAnalyserTool::class)]
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
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
final class ComposerDependencyAnalyserToolTest extends TestCase
{
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
    public function aCleanAnalysisPassesAndRunsThePharWithTheShippedConfig(): void
    {
        $this->factory->processes->willSucceed('No unused dependencies');
        $phar    = $this->factory->context()->config->paths->pharDir . '/composer-dependency-analyser.phar';
        $root    = $this->factory->project->path;
        $library = \dirname(__DIR__, 4);

        $result = new ComposerDependencyAnalyserTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            [
                '/usr/bin/php', '-d', 'memory_limit=4G', '-d', 'display_errors=stderr',
                '-f', $phar, '--',
                '--config=' . $library . '/configDefaults/generic/composer-dependency-analyser.php',
                '--composer-json=' . $root . '/composer.json',
            ],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function aProjectConfigOverrideWins(): void
    {
        $this->factory->processes->willSucceed();

        $override = $this->factory->project->write('qaConfig/composer-dependency-analyser.php', '<?php');

        new ComposerDependencyAnalyserTool()->run($this->factory->context());

        self::assertContains('--config=' . $override, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function findingsFailTheLaneWithTheWholeGuidanceAndTheIdentifier(): void
    {
        $this->factory->processes->willFail(1, 'Found 2 unused dependencies!');

        $result = new ComposerDependencyAnalyserTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer-dependency-analyser reported problems (exit 1)', $result->summary);
        self::assertSame(
            <<<'TEXT'


                HOW TO FIX
                ----------
                The tool exits 1 for findings AND for its own errors, so read the output above
                first: a red "Error:" line means it could not run (a bad config path, an
                unreadable composer.json) and nothing was analysed.

                Otherwise each finding has its own fix:

                  UNUSED DEPENDENCY — declared in composer.json, used nowhere in the scanned
                  paths. Remove it. If it is used at run time in a way no static scan can see
                  (a plugin, a binary you invoke, an extension a tool needs), keep it and say so
                  with ->ignoreErrorsOnPackage() in your config, which records the reason in
                  version control instead of leaving the package unexplained.

                  SHADOW DEPENDENCY — used in your code but only installed because something
                  else depends on it. Require it explicitly; the package that pulls it in today
                  is free to drop it tomorrow.

                  DEV DEPENDENCY IN PROD — production code uses a require-dev package. A
                  --no-dev install will not have it. Move the package to require, or move the
                  usage out of production code.

                  PROD DEPENDENCY ONLY IN DEV — required for production but only ever used by
                  tests. Move it to require-dev so a production install stops carrying it.

                  UNKNOWN CLASS / FUNCTION — the symbol could not be autoloaded, so its package
                  could not be determined and it was NOT checked. Classes supplied by a PHAR
                  (PHPStan, PHPArkitect) land here legitimately: list them with
                  ->ignoreUnknownClassesRegex() rather than leaving the check blind.

                Configuration goes in qaConfig/composer-dependency-analyser.php, which replaces the
                shipped default entirely.

                🪪  phpqaci.composerDependencyAnalyser  (vendor/bin/rule-doc phpqaci.composerDependencyAnalyser)

                TEXT,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function theSummaryCarriesTheToolsOwnExitCode(): void
    {
        $this->factory->processes->willFail(255, 'Error: config not found');

        $result = new ComposerDependencyAnalyserTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer-dependency-analyser reported problems (exit 255)', $result->summary);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new ComposerDependencyAnalyserTool();

        self::assertSame('composerDependencyAnalyser', $tool->name());
        self::assertSame('phpqaci.composerDependencyAnalyser', $tool->identifier());
    }
}
