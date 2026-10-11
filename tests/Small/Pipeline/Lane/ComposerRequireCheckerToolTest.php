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
final class ComposerRequireCheckerToolTest extends TestCase
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
    public function aCleanCheckPassesAndRunsThePharWithTheShippedConfig(): void
    {
        $this->factory->processes->willSucceed('There were no unknown symbols found.');
        $root    = $this->factory->project->path;
        $library = \dirname(__DIR__, 4);

        $result = new ComposerRequireCheckerTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            [
                '/usr/bin/php', '-d', 'memory_limit=4G',
                '-f', $library . '/vendor-phar/composer-require-checker.phar', '--',
                'check', '--config-file=' . $library . '/configDefaults/generic/composerRequireChecker.json', '--', $root . '/composer.json',
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
        $override = $this->factory->project->write('qaConfig/composerRequireChecker.json', '{}');

        new ComposerRequireCheckerTool()->run($this->factory->context());

        self::assertContains('--config-file=' . $override, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function aFailureIsFollowedByTheHowToFixGuidanceAndTheIdentifier(): void
    {
        $this->factory->processes->willFail(1, 'The following unknown symbols were found: Foo\Bar');

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

    /** The whole block, line for line, blank lines included: its layout is what a reader of a failed run scans. */
    #[Test]
    public function aFailurePrintsTheWholeGuidanceBlockThenTheIdentifier(): void
    {
        $this->factory->processes->willFail(2, 'The following unknown symbols were found: Foo\Bar');

        $result = new ComposerRequireCheckerTool()->run($this->factory->context());

        self::assertSame('Composer Require Checker failed (exit 2)', $result->summary);
        self::assertSame(<<<'BLOCK'


            To fix these issues, you probably need to add things to your 'require' section in your projects composer.json

            You might do this by moving things from 'require-dev', or it could be things that are brought in by your dependencies that you need to add.

            The ones that say 'ext-json' or similar, you just need to add '"ext-json": "*"'

            Of course the other option is that you refactor your code and stop using your dev dependencies in your production code

            NOTE - Safe php - special case - you need to modify the scan-files section and add in files as required.


            HOW TO FIX
            ----------
            1. Add the package to your 'require' section in composer.json (not require-dev).
               If the package is currently in require-dev, either move it to require or stop
               using it in production code (src/).

            2. For PHP extensions (ext-json, ext-mbstring, etc.):
               composer require ext-json:"*"

            3. For Safe functions (thecodingmachine/safe):
               Add the generated file paths to the 'scan-files' section in your
               qaConfig/composerRequireChecker.json override.

            ⚠️  WARNING — DO NOT ADD THESE SYMBOLS TO THE WHITELIST
            --------------------------------------------------------
            Never add symbols from dev-dependency packages to the symbol-whitelist.
            The whitelist exists for language built-ins and unavoidable edge cases only.

            If a symbol's package is in require-dev but your production code (src/) uses it,
            adding it to the whitelist silently hides a real problem:

              • In a production (non-dev) install, Composer does NOT install require-dev packages.
              • Transitive dependencies of require-dev packages are also absent.
              • Your application will crash at runtime with class-not-found errors.

            The correct fix is always one of:
              a) Move the package from require-dev to require   (it IS a runtime dependency)
              b) Remove the usage from production code          (it should NOT be a runtime dependency)

            There is no scenario where whitelisting a dev-dependency symbol is the right answer.

            🪪  phpqaci.composerRequireChecker  (vendor/bin/rule-doc phpqaci.composerRequireChecker)

            BLOCK, $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new ComposerRequireCheckerTool();

        self::assertSame('composerRequireChecker', $tool->name());
        self::assertSame('phpqaci.composerRequireChecker', $tool->identifier());
    }
}
