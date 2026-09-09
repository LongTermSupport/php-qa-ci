<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lane\TwigCsFixerTool;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
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
#[CoversClass(TwigCsFixerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(ToolContext::class)]
#[Small]
final class TwigCsFixerToolTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    private const string TEMPLATES = 'templates';

    private const string TEMPLATE_FILE = 'templates/page.html.twig';

    private const string CLEAN_TEMPLATE = '{{ x }}';

    private const string TWIG_PACKAGE_FILE = 'vendor/twig/twig/composer.json';

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
    public function aProjectWithoutTwigInstalledIsSkippedWithoutRunningAnything(): void
    {
        $result = new TwigCsFixerTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('twig/twig not installed', $result->summary);
    }

    /**
     * The whole point of the lane's gate: the PHAR is standalone, so a project
     * that uses Twig without Symfony still gets its templates checked.
     */
    #[Test]
    public function aNonSymfonyProjectWithTwigInstalledIsStillChecked(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed();
        $this->factory->project->write(self::TEMPLATE_FILE, self::CLEAN_TEMPLATE);

        $result = new TwigCsFixerTool()->run($this->twigContext());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertContains(
            $this->factory->project->path . '/' . self::TEMPLATES,
            $this->factory->processes->lastSpec()->command,
        );
    }

    #[Test]
    public function aProjectWithTwigButNoTwigDirectoryIsSkipped(): void
    {
        $result = new TwigCsFixerTool()->run($this->twigContext());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('no twig directories', $result->summary);
    }

    #[Test]
    public function aReadOnlyRunChecksWithoutFixingAndPassesWhenClean(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed();
        $this->factory->project->write(self::TEMPLATE_FILE, self::CLEAN_TEMPLATE);
        $library = \dirname(__DIR__, 4);

        $result = new TwigCsFixerTool()->run($this->twigContext());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $command = $this->factory->processes->lastSpec()->command;
        self::assertContains($library . '/vendor-phar/twig-cs-fixer.phar', $command);
        self::assertContains('lint', $command);
        self::assertContains('--config=' . $library . '/configDefaults/generic/.twig-cs-fixer.php', $command);
        self::assertContains($this->factory->project->path . '/' . self::TEMPLATES, $command);
        self::assertNotContains('--fix', $command, 'a read-only run must never fix');
    }

    #[Test]
    public function aWritableRunPassesTheFixFlag(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed();
        $this->factory->project->write(self::TEMPLATE_FILE, self::CLEAN_TEMPLATE);

        new TwigCsFixerTool()->run($this->twigContext(readOnly: false));

        self::assertContains('--fix', $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function onlyDirectoriesThatExistArePassedToTheFixer(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed();
        $this->factory->project->write(self::TEMPLATE_FILE, self::CLEAN_TEMPLATE);
        $this->factory->project->write(self::TWIG_PACKAGE_FILE, '{}');

        $config = $this->factory->builder(platform: PlatformEnum::Symfony)
            ->withTwigDirectories(self::TEMPLATES, 'src/Resources/views')
            ->build()
        ;

        new TwigCsFixerTool()->run($this->factory->context($config));

        $root    = $this->factory->project->path;
        $command = $this->factory->processes->lastSpec()->command;
        self::assertContains($root . '/' . self::TEMPLATES, $command);
        self::assertNotContains($root . '/src/Resources/views', $command, 'a missing directory would make the fixer error');
    }

    #[Test]
    public function aPendingFixInAReadOnlyRunFailsWithTheWouldModifyGuidance(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(1, 'violation found');
        $this->factory->project->write(self::TEMPLATE_FILE, '{{x}}');

        $result  = new TwigCsFixerTool()->run($this->twigContext());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('READ-ONLY run', $printed);
        self::assertStringContainsString(TwigCsFixerTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aWritableRunThatCannotFixEverythingFailsAndSaysSo(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(1, 'unfixable violation');
        $this->factory->project->write(self::TEMPLATE_FILE, '{{x}}');

        $result  = new TwigCsFixerTool()->run($this->twigContext(readOnly: false));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString(TwigCsFixerTool::IDENTIFIER, $printed);

        foreach ([
            'Twig CS Fixer applied every fix it could and violations remain, so these',
            'need a human. It exits non-zero whenever anything is left, including after',
            'a successful --fix run.',
            'TO FIX: correct the templates listed above by hand, then re-run:',
            'vendor/bin/qa -t twigcs',
            'To change which rules apply, copy the shipped config to',
            'qaConfig/.twig-cs-fixer.php',
        ] as $expected) {
            self::assertStringContainsString($expected, $printed);
        }
    }

    #[Test]
    public function exitCodeTwoIsACrashRatherThanAFinding(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(2, 'Error: bad config');
        $this->factory->project->write(self::TEMPLATE_FILE, self::CLEAN_TEMPLATE);

        $result = new TwigCsFixerTool()->run($this->twigContext());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('twig-cs-fixer could not run (exit 2)', $result->summary);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new TwigCsFixerTool();

        self::assertSame('twigCsFixer', $tool->name());
        self::assertSame('phpqaci.twigCsFixer', $tool->identifier());
    }

    /**
     * A project with Twig installed. The platform is deliberately left generic:
     * this lane is gated on the library, not on Symfony.
     */
    private function twigContext(bool $readOnly = true, PlatformEnum $platform = PlatformEnum::Generic): ToolContext
    {
        $this->factory->project->write(self::TWIG_PACKAGE_FILE, '{}');

        return $this->factory->context(
            $this->factory->builder(readOnly: $readOnly, platform: $platform)->build(),
        );
    }
}
