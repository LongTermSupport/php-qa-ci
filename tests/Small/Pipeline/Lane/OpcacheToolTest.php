<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\OpcacheDefects;
use LTS\PHPQA\Pipeline\Lane\OpcacheTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
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
#[CoversClass(OpcacheTool::class)]
#[UsesClass(OpcacheDefects::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Opcache\DumpParser::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Opcache\Dto\ConstComparisonDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[Small]
final class OpcacheToolTest extends TestCase
{
    private const string AFFECTED = "8.5.10\n";

    private const string CLEAN_DUMP = <<<'DUMP'
        $_main:
             ; (lines=1, args=0, vars=0, tmps=0)
             ; (after optimizer)
             ; %1$s/src/A.php:1-9
        0000 RETURN int(1)

        $_main:
             ; (lines=1, args=0, vars=0, tmps=0)
             ; (after optimizer)
             ; %1$s/tests/ATest.php:1-9
        0000 RETURN int(1)
        DUMP;

    private const string BAD_DUMP = <<<'DUMP'
        App\Gateway::find:
             ; (lines=9, args=1, vars=2, tmps=2)
             ; (after optimizer)
             ; %1$s/src/A.php:12-20
        0003 T1 = TYPE_CHECK (null) CV1($x)
        0004 JMPZ T1 0006
        0005 T1 = IS_NOT_IDENTICAL null array(...)

        $_main:
             ; (lines=1, args=0, vars=0, tmps=0)
             ; (after optimizer)
             ; %1$s/tests/ATest.php:1-9
        0000 RETURN int(1)
        DUMP;

    private const string CLEAN_DUMP_SRC_ONLY = <<<'DUMP'
        $_main:
             ; (lines=1, args=0, vars=0, tmps=0)
             ; (after optimizer)
             ; %s/src/A.php:1-9
        0000 RETURN int(1)
        DUMP;

    private const string SRC_A = 'src/A.php';

    private const string PHP_STUB = "<?php\n";

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(self::SRC_A, self::PHP_STUB);
        $this->factory->project->write('tests/ATest.php', self::PHP_STUB);
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function everyCheckedPhpFileIsCompiledThroughTheOptimizerWithTheDefaultMaskAndTheDump(): void
    {
        $root = $this->factory->project->path;
        $this->factory->processes->willSucceed(self::AFFECTED)->willSucceed(\sprintf(self::CLEAN_DUMP, $root));

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        $paths = $this->factory->context()->config->paths;
        self::assertSame(
            [
                '/usr/bin/php', '-d', 'memory_limit=4G',
                '-d', 'opcache.enable_cli=1',
                '-d', 'opcache.optimization_level=0x7FFEBFFF',
                '-d', 'opcache.opt_debug_level=0x20000',
                '-d', 'opcache.file_update_protection=0',
                '-f', $paths->libraryRoot . '/bin/opcache-optimizer-dump', '--',
                $root . '/tests/ATest.php', $root . '/src/A.php',
            ],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertFalse($this->factory->processes->lastSpec()->streamOutput);
        self::assertStringContainsString('2 file(s) compiled through the optimizer', $this->factory->output->fetch());
    }

    #[Test]
    public function aConstantVersusConstantComparisonFailsTheLaneNamingTheFunctionAndLines(): void
    {
        $root = $this->factory->project->path;
        $this->factory->processes->willSucceed(self::AFFECTED)->willSucceed(\sprintf(self::BAD_DUMP, $root));

        $result  = new OpcacheTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 comparison(s) the optimizer left as constant-vs-constant', $result->summary);
        self::assertStringContainsString($root . '/src/A.php:12-20  App\Gateway::find  IS_NOT_IDENTICAL null array(...)', $printed);
        self::assertStringContainsString('opcache.optimization_level=0x7FFEBFDF', $printed);
        self::assertStringContainsString(OpcacheTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aFileTheOptimizerNeverDumpedIsACrashRatherThanASilentPass(): void
    {
        $root = $this->factory->project->path;
        // OPcache skips a file it will not cache (file_update_protection, a
        // blacklist, a full SHM), and prints no dump for it. Reporting a pass on
        // a file nothing looked at is the one outcome this lane must never give.
        $this->factory->processes->willSucceed(self::AFFECTED)->willSucceed(\sprintf(self::CLEAN_DUMP_SRC_ONLY, $root));

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('1 file(s) produced no optimizer dump, so nothing verified them', $result->summary);
        self::assertStringContainsString($root . '/tests/ATest.php', $this->factory->output->fetch());
    }

    #[Test]
    public function anUnaffectedPhpSkipsWithoutCompilingAnything(): void
    {
        $this->factory->processes->willSucceed("8.4.22\n");

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('PHP 8.4.22 is outside the affected range', $result->summary);
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function aHostWithoutOpcacheSkipsWithANote(): void
    {
        $this->factory->processes->willSucceed(self::AFFECTED)->willFail(2, "OPcache is not loaded\n");

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('OPcache is not loaded for the CLI, so nothing can be compiled through the optimizer', $result->summary);
    }

    #[Test]
    public function anyOtherFailureOfTheDumpIsACrash(): void
    {
        $this->factory->processes->willSucceed(self::AFFECTED)->willFail(255, 'Fatal');

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('optimizer dump crashed (exit 255)', $result->summary);
    }

    #[Test]
    public function ignoredPathsAreLeftOutAndNoFilesMeansAPass(): void
    {
        $this->factory->processes->willSucceed(self::AFFECTED);
        $config = $this->factory->builder()->withIgnoredPaths('src', 'tests')->build();

        $result = new OpcacheTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new OpcacheTool();

        self::assertSame('opcache', $tool->name());
        self::assertSame('phpqaci.opcache', $tool->identifier());
        self::assertSame(OpcacheDefects::IDENTIFIER, $tool->identifier());
    }
}
