<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lane\YamlLintTool;
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
#[CoversClass(YamlLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[Small]
final class YamlLintToolTest extends TestCase
{
    private const string CONFIG_DIR = 'config';

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
    public function aProjectWithoutSymfonyYamlIsSkippedWithoutRunningAnything(): void
    {
        $this->factory->project->mkdir(YamlLintTool::CONSOLE_PACKAGE);
        $this->factory->project->mkdir(self::CONFIG_DIR);

        $result = new YamlLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('symfony/yaml not installed', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aProjectWithoutSymfonyConsoleIsSkippedBecauseTheScriptNeedsIt(): void
    {
        $this->factory->project->write(YamlLintTool::YAML_LINT, '');
        $this->factory->project->mkdir(self::CONFIG_DIR);

        $result = new YamlLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('symfony/console not installed', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function noExistingYamlDirectoryIsSkippedWithoutRunningAnything(): void
    {
        $this->installYamlLint();
        $root = $this->factory->project->path;

        $result = new YamlLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(
            'Yaml Lint: none of the configured YAML directories exist (checked: ' . $root . '/config) — skipping.',
            $this->factory->output->fetch(),
        );
        self::assertSame([], $this->factory->processes->specs);
    }

    /**
     * The point of the gate: the script is standalone, so a generic project
     * that has symfony/yaml gets its configuration linted without bin/console.
     */
    #[Test]
    public function aCleanLintOnAGenericProjectPassesAndRunsYamlLintWithParseTagsOverTheConfigDirectory(): void
    {
        $this->factory->processes->willSucceed('All 4 YAML files contain valid syntax.');
        $this->installYamlLint();
        $this->factory->project->mkdir(self::CONFIG_DIR);

        $root = $this->factory->project->path;

        $result = new YamlLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-f', YamlLintTool::YAML_LINT, '--', '--parse-tags', $root . '/config'],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertTrue($this->factory->processes->lastSpec()->streamOutput);
        self::assertStringNotContainsString(YamlLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function onlyTheConfiguredDirectoriesThatExistArePassedInOrder(): void
    {
        $this->factory->processes->willSucceed();
        $this->installYamlLint();
        $this->factory->project->mkdir(self::CONFIG_DIR);
        $this->factory->project->mkdir('translations');

        $root   = $this->factory->project->path;
        $config = $this->factory->builder(platform: PlatformEnum::Symfony)
            ->withYamlDirectories(self::CONFIG_DIR, 'missing', 'translations')
            ->build()
        ;

        new YamlLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', '--parse-tags', $root . '/config', $root . '/translations'],
            \array_slice($this->factory->processes->lastSpec()->command, 5),
        );
    }

    #[Test]
    public function aNonZeroExitFailsWithTheIdentifierTrailer(): void
    {
        $this->factory->processes->willFail(1, 'Unable to parse at line 3');
        $this->installYamlLint();
        $this->factory->project->mkdir(self::CONFIG_DIR);

        $result = new YamlLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('Yaml Lint failed (exit 1)', $result->summary);
        self::assertStringContainsString(YamlLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new YamlLintTool();

        self::assertSame('yamlLint', $tool->name());
        self::assertSame('phpqaci.yamlLint', $tool->identifier());
    }

    private function installYamlLint(): void
    {
        $this->factory->project->write(YamlLintTool::YAML_LINT, '');
        $this->factory->project->mkdir(YamlLintTool::CONSOLE_PACKAGE);
    }
}
