<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhpcpdTool;
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
#[CoversClass(PhpcpdTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\IgnoredPaths::class)]
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
final class PhpcpdToolTest extends TestCase
{
    private const string EXCLUDE = '--exclude';

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
    public function aCleanRunWritesTheJsonReportOverTheCheckedPaths(): void
    {
        $this->factory->processes->willSucceed('0% duplicated lines');
        $phar   = $this->factory->context()->config->paths->pharDir . '/phpcpd.phar';
        $config = $this->factory->builder()->build();

        $result = new PhpcpdTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $command = $this->factory->processes->lastSpec()->command;
        self::assertContains($phar, $command);
        self::assertContains(
            '--log-json=' . $this->factory->project->path . '/var/qa/phpcpd/' . PhpcpdTool::LOG_FILE,
            $command,
        );
        foreach ($config->pathsToCheck as $path) {
            self::assertContains($path, $command);
        }
    }

    #[Test]
    public function noIgnoredPathMeansNoExclude(): void
    {
        $this->factory->processes->willSucceed();

        new PhpcpdTool()->run($this->factory->context());

        self::assertNotContains(self::EXCLUDE, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function eachIgnoredPathIsExcludedAsADirectoryOrAsAFile(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->mkdir('tests/assets');
        $this->factory->project->write('src/Legacy.php', "<?php\n");

        $config = $this->factory->builder()->withIgnoredPaths('tests/assets', 'src/Legacy.php')->build();

        new PhpcpdTool()->run($this->factory->context($config));

        // phpcpd excludes by substring, so a directory carries its trailing
        // slash: tests/assets must not also drop tests/assetsExtra.
        $command = $this->factory->processes->lastSpec()->command;
        self::assertSame(
            [self::EXCLUDE, $root . '/tests/assets/', self::EXCLUDE, $root . '/src/Legacy.php', $root . '/tests', $root . '/src'],
            \array_slice($command, 7),
        );
    }

    #[Test]
    public function foundClonesDoNotFailTheLane(): void
    {
        $this->factory->processes->willFail(1, '2.4% duplicated lines');

        $result = new PhpcpdTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, 'duplication is a judgement call, never a gate');
        self::assertStringNotContainsString('could not run', $this->factory->output->fetch());
    }

    #[Test]
    public function aCrashIsReportedOnScreenAndStillDoesNotFailTheLane(): void
    {
        $this->factory->processes->willFail(255, 'segfault');

        $result = new PhpcpdTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('phpcpd could not run (exit 255)', $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpcpdTool();

        self::assertSame('phpcpd', $tool->name());
        self::assertSame('phpqaci.phpcpd', $tool->identifier());
    }
}
