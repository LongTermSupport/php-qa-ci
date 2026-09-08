<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool;
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
#[CoversClass(ComposerRequireCheckerTool::class)]
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
final class ComposerRequireCheckerToolTest extends TestCase
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
    public function aCleanCheckPassesAndRunsThePharWithTheShippedConfig(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed('There were no unknown symbols found.');
        $root    = $this->factory->project->path;
        $library = \dirname(__DIR__, 4);

        $result = new ComposerRequireCheckerTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            [
                '/usr/bin/php', '-n', '-c', $root . '/var/qa/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G',
                '-f', $library . '/vendor-phar/composer-require-checker.phar', '--',
                'check', '--config-file=' . $library . '/configDefaults/generic/composerRequireChecker.json', '--', $root . '/composer.json',
            ],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertStringNotContainsString(ComposerRequireCheckerTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function aProjectConfigOverrideWins(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed();
        $override = $this->factory->project->write('qaConfig/composerRequireChecker.json', '{}');

        new ComposerRequireCheckerTool()->run($this->factory->context());

        self::assertContains('--config-file=' . $override, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function aFailureIsFollowedByTheHowToFixGuidanceAndTheIdentifier(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willFail(1, 'The following unknown symbols were found: Foo\Bar');

        $result  = new ComposerRequireCheckerTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('Composer Require Checker failed (exit 1)', $result->summary);
        self::assertStringContainsString("To fix these issues, you probably need to add things to your 'require' section", $printed);
        self::assertStringContainsString('HOW TO FIX', $printed);
        self::assertStringContainsString('composer require ext-json:"*"', $printed);
        self::assertStringContainsString('DO NOT ADD THESE SYMBOLS TO THE WHITELIST', $printed);
        self::assertStringContainsString('There is no scenario where whitelisting a dev-dependency symbol is the right answer.', $printed);
        self::assertStringContainsString(ComposerRequireCheckerTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new ComposerRequireCheckerTool();

        self::assertSame('composerRequireChecker', $tool->name());
        self::assertSame('phpqaci.composerRequireChecker', $tool->identifier());
    }
}
