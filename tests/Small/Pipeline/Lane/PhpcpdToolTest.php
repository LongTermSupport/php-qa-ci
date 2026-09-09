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
#[Small]
final class PhpcpdToolTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    private const string BINARY = 'vendor/bin/phpcpd';

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
    public function itIsSkippedWhenTheBinaryIsAbsent(): void
    {
        $result = new PhpcpdTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('phpcpd not installed', $result->summary);
    }

    #[Test]
    public function aCleanRunWritesTheJsonReportOverTheCheckedPaths(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willSucceed('0% duplicated lines');
        $binary = $this->factory->project->write(self::BINARY, self::SHEBANG);
        $config = $this->factory->builder()->build();

        $result = new PhpcpdTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $command = $this->factory->processes->lastSpec()->command;
        self::assertContains($binary, $command);
        self::assertContains(
            '--log-json=' . $this->factory->project->path . '/var/qa/phpcpd/' . PhpcpdTool::LOG_FILE,
            $command,
        );
        foreach ($config->pathsToCheck as $path) {
            self::assertContains($path, $command);
        }
    }

    #[Test]
    public function foundClonesDoNotFailTheLane(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(1, '2.4% duplicated lines');
        $this->factory->project->write(self::BINARY, self::SHEBANG);

        $result = new PhpcpdTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, 'duplication is a judgement call, never a gate');
        self::assertStringNotContainsString('could not run', $this->factory->output->fetch());
    }

    #[Test]
    public function aCrashIsReportedOnScreenAndStillDoesNotFailTheLane(): void
    {
        $this->factory->processes->willSucceed(self::PHP_VERSION)->willFail(255, 'segfault');
        $this->factory->project->write(self::BINARY, self::SHEBANG);

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
