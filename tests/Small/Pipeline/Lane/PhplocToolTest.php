<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhplocTool;
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
#[CoversClass(PhplocTool::class)]
#[UsesClass(ContextFactory::class)]
#[Small]
final class PhplocToolTest extends TestCase
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
    public function withoutAPhplocBinaryTheLaneIsSkippedAndNothingRuns(): void
    {
        $result = new PhplocTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('phploc not installed', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function withPhplocInstalledItRunsOverTheCheckedPathsAndPasses(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willSucceed('Lines of Code (LOC) 1234');
        $root   = $this->factory->project->path;
        $phploc = $this->factory->project->write('vendor/bin/phploc', "#!/usr/bin/env php\n");

        $result = new PhplocTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['/usr/bin/php', '-n', '-c', $root . '/var/qa/phpqa-no-xdebug.8.5.10.ini', '-d', 'memory_limit=4G', '-f', $phploc, '--', $root . '/tests', $root . '/src'],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
    }

    #[Test]
    public function aNonZeroExitIsStillInformationalAndPasses(): void
    {
        $this->factory->processes->willSucceed('8.5.10')->willFail(1, 'phploc blew up');
        $this->factory->project->write('vendor/bin/phploc', "#!/usr/bin/env php\n");

        $result = new PhplocTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringNotContainsString(PhplocTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhplocTool();

        self::assertSame('phploc', $tool->name());
        self::assertSame('phpqaci.phploc', $tool->identifier());
    }
}
