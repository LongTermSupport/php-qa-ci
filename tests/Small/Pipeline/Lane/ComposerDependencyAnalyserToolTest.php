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
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[Small]
final class ComposerDependencyAnalyserToolTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    private const string BINARY = 'vendor/bin/composer-dependency-analyser';

    private const string SHEBANG = "#!/usr/bin/env php\n";

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write('var/qa/phpqa-no-xdebug.8.5.10.ini', '');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCleanAnalysisPassesAndRunsTheBinaryWithTheShippedConfig(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed('No unused dependencies');
        $binary  = $this->factory->project->write(self::BINARY, self::SHEBANG);
        $root    = $this->factory->project->path;
        $library = \dirname(__DIR__, 4);

        $result = new ComposerDependencyAnalyserTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            [
                '/usr/bin/php', '-n', '-c', $root . '/var/qa/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G',
                '-f', $binary, '--',
                '--config=' . $library . '/configDefaults/generic/composer-dependency-analyser.php',
                '--composer-json=' . $root . '/composer.json',
            ],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertStringNotContainsString(ComposerDependencyAnalyserTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function aProjectConfigOverrideWins(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed();
        $this->factory->project->write(self::BINARY, self::SHEBANG);
        $override = $this->factory->project->write('qaConfig/composer-dependency-analyser.php', '<?php');

        new ComposerDependencyAnalyserTool()->run($this->factory->context());

        self::assertContains('--config=' . $override, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function findingsFailTheLaneWithGuidanceCoveringEveryErrorTypeAndTheIdentifier(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(1, 'Found 2 unused dependencies!');
        $this->factory->project->write(self::BINARY, self::SHEBANG);

        $result  = new ComposerDependencyAnalyserTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer-dependency-analyser reported problems (exit 1)', $result->summary);
        self::assertStringContainsString('UNUSED DEPENDENCY', $printed);
        self::assertStringContainsString('SHADOW DEPENDENCY', $printed);
        self::assertStringContainsString('DEV DEPENDENCY IN PROD', $printed);
        self::assertStringContainsString('PROD DEPENDENCY ONLY IN DEV', $printed);
        self::assertStringContainsString('UNKNOWN CLASS / FUNCTION', $printed);
        self::assertStringContainsString(ComposerDependencyAnalyserTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function theGuidanceSaysExitOneIsAmbiguousBetweenFindingsAndFailure(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(1, 'Error: config not found');
        $this->factory->project->write(self::BINARY, self::SHEBANG);

        new ComposerDependencyAnalyserTool()->run($this->factory->context());

        self::assertStringContainsString(
            'The tool exits 1 for findings AND for its own errors',
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aMissingBinaryFailsRatherThanSkippingSinceItIsARequireDependency(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION);

        $result  = new ComposerDependencyAnalyserTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('composer-dependency-analyser is not installed', $result->summary);
        self::assertStringContainsString('the install is incomplete', $printed);
        self::assertStringContainsString(ComposerDependencyAnalyserTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new ComposerDependencyAnalyserTool();

        self::assertSame('composerDependencyAnalyser', $tool->name());
        self::assertSame('phpqaci.composerDependencyAnalyser', $tool->identifier());
    }
}
