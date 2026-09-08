<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lane\TwigLintTool;
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
#[CoversClass(TwigLintTool::class)]
#[UsesClass(ContextFactory::class)]
#[Small]
final class TwigLintToolTest extends TestCase
{
    private const string CONSOLE_LISTING = "Available commands:\n  lint:twig  Lint a Twig template\n";

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
        $result = new TwigLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('not a Symfony project', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aConsoleWithoutLintTwigIsSkipped(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed("Available commands:\n  cache:clear\n");
        $this->factory->project->mkdir('vendor/symfony/twig-bundle');

        $result = new TwigLintTool()->run($this->factory->context($this->symfonyConfig()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('Twig Lint not found in bin/console, skipping', $this->factory->output->fetch());
        self::assertCount(2, $this->factory->processes->specs, 'only the version probe and the command listing ran');
        self::assertFalse($this->factory->processes->lastSpec()->streamOutput, 'the listing is captured, not streamed');
        self::assertSame(
            ['/usr/bin/php', '-n', '-c', $this->factory->project->path . '/var/qa/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G', '-f', 'bin/console', '--'],
            $this->factory->processes->lastSpec()->command,
        );
    }

    #[Test]
    public function aMissingTwigBundleIsSkipped(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed(self::CONSOLE_LISTING);

        $result = new TwigLintTool()->run($this->factory->context($this->symfonyConfig()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('Twig Not Installed, nothing to do', $this->factory->output->fetch());
        self::assertCount(2, $this->factory->processes->specs, 'lint:twig itself never ran');
    }

    #[Test]
    public function aCleanLintPassesAndRunsLintTwigOverTheTwigDirectories(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed(self::CONSOLE_LISTING)->willSucceed('8.5.10')->willSucceed('All 3 Twig files contain valid syntax.');
        $this->factory->project->mkdir('vendor/symfony/twig-bundle');
        $root = $this->factory->project->path;

        $result = new TwigLintTool()->run($this->factory->context($this->symfonyConfig()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['/usr/bin/php', '-n', '-c', $root . '/var/qa/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G', '-f', 'bin/console', '--', 'lint:twig', $root . '/templates'],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertTrue($this->factory->processes->lastSpec()->streamOutput);
        self::assertStringNotContainsString(TwigLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function configuredTwigDirectoriesArePassedInOrder(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed(self::CONSOLE_LISTING)->willSucceed('8.5.10')->willSucceed();
        $this->factory->project->mkdir('vendor/symfony/twig-bundle');
        $root   = $this->factory->project->path;
        $config = $this->factory->builder(platform: PlatformEnum::Symfony)->withTwigDirectories('templates', 'src/Resources/views')->build();

        new TwigLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', 'lint:twig', $root . '/templates', $root . '/src/Resources/views'],
            \array_slice($this->factory->processes->lastSpec()->command, 8),
        );
    }

    #[Test]
    public function aNonZeroExitFailsWithTheIdentifierTrailer(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed(self::CONSOLE_LISTING)->willSucceed('8.5.10')->willFail(1, 'Unexpected token in templates/base.html.twig');
        $this->factory->project->mkdir('vendor/symfony/twig-bundle');

        $result = new TwigLintTool()->run($this->factory->context($this->symfonyConfig()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('Twig Lint failed (exit 1)', $result->summary);
        self::assertStringContainsString(TwigLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new TwigLintTool();

        self::assertSame('twigLint', $tool->name());
        self::assertSame('phpqaci.twigLint', $tool->identifier());
    }

    private function symfonyConfig(): QaConfigDto
    {
        return $this->factory->builder(platform: PlatformEnum::Symfony)->build();
    }
}
