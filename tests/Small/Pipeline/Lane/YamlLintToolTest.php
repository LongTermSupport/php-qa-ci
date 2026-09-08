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
#[UsesClass(ContextFactory::class)]
#[Small]
final class YamlLintToolTest extends TestCase
{
    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        // Pre-generate the no-Xdebug ini so PhpInvoker only probes the PHP version per invocation.
        $this->factory->project->write('var/qa/phpqa-no-xdebug.8.5.10.ini', '');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aGenericProjectIsSkippedWithoutRunningAnything(): void
    {
        $this->factory->project->mkdir('config');

        $result = new YamlLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('not a Symfony project', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function noExistingYamlDirectoryIsSkippedWithoutRunningAnything(): void
    {
        $root = $this->factory->project->path;

        $result = new YamlLintTool()->run($this->factory->context($this->symfonyConfig()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(
            'Yaml Lint: none of the configured YAML directories exist (checked: ' . $root . '/config) — skipping.',
            $this->factory->output->fetch(),
        );
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aCleanLintPassesAndRunsLintYamlWithParseTagsOverTheConfigDirectory(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed('All 4 YAML files contain valid syntax.');
        $this->factory->project->mkdir('config');
        $root = $this->factory->project->path;

        $result = new YamlLintTool()->run($this->factory->context($this->symfonyConfig()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['/usr/bin/php', '-n', '-c', $root . '/var/qa/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G', '-f', 'bin/console', '--', 'lint:yaml', '--parse-tags', $root . '/config'],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertTrue($this->factory->processes->lastSpec()->streamOutput);
        self::assertStringNotContainsString(YamlLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function onlyTheConfiguredDirectoriesThatExistArePassedInOrder(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed();
        $this->factory->project->mkdir('config');
        $this->factory->project->mkdir('translations');

        $root   = $this->factory->project->path;
        $config = $this->factory->builder(platform: PlatformEnum::Symfony)
            ->withYamlDirectories('config', 'missing', 'translations')
            ->build()
        ;

        new YamlLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', 'lint:yaml', '--parse-tags', $root . '/config', $root . '/translations'],
            \array_slice($this->factory->processes->lastSpec()->command, 8),
        );
    }

    #[Test]
    public function aNonZeroExitFailsWithTheIdentifierTrailer(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willFail(1, 'Unable to parse at line 3');
        $this->factory->project->mkdir('config');

        $result = new YamlLintTool()->run($this->factory->context($this->symfonyConfig()));

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

    private function symfonyConfig(): QaConfigDto
    {
        return $this->factory->builder(platform: PlatformEnum::Symfony)->build();
    }
}
