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
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
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

    private const string TESTS_A = 'tests/ATest.php';

    private const string PHP_STUB = "<?php\n";

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(self::SRC_A, self::PHP_STUB);
        $this->factory->project->write(self::TESTS_A, self::PHP_STUB);
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
                '/usr/bin/php', '-d', 'memory_limit=4G', '-d', 'display_errors=stderr',
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

    /** A dump a shell reports as killed by a signal says which, and a segfault says what to do. */
    #[Test]
    public function aDumpKilledByASignalIsACrashNamingTheSignal(): void
    {
        $this->factory->processes->willSucceed(self::AFFECTED)->willReturn(new ProcessResultDto(139, '', '', \SIGSEGV));

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('optimizer dump crashed (exit 139, killed by signal 11 (SIGSEGV): ' . \LTS\PHPQA\ComposerPlugin\ChildExit::segfaultAdvice() . ')', $result->summary);
    }

    /** A dump that exited 139 itself relays a crash below it, and is not called killed. */
    #[Test]
    public function aDumpThatExited139ItselfIsACrashNotCalledAKill(): void
    {
        $this->factory->processes->willSucceed(self::AFFECTED)->willFail(139, '');

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome);
        self::assertSame('optimizer dump crashed (exit 139' . \LTS\PHPQA\ComposerPlugin\ChildExit::exitedItself(139) . ')', $result->summary);
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
    public function noFilesToCompilePassesWithExactlyThatNote(): void
    {
        $this->factory->processes->willSucceed(self::AFFECTED);
        $config = $this->factory->builder()->withIgnoredPaths('src', 'tests')->build();

        new OpcacheTool()->run($this->factory->context($config));

        self::assertSame("No PHP files to compile\n", $this->factory->output->fetch());
    }

    #[Test]
    public function aCrashedDumpPrintsWhatTheDumpPrinted(): void
    {
        $this->factory->processes->willSucceed(self::AFFECTED)->willFail(255, 'Fatal: out of memory');

        new OpcacheTool()->run($this->factory->context());

        self::assertSame("Fatal: out of memory\n", $this->factory->output->fetch());
    }

    #[Test]
    public function anUndumpedFilePrintsEachFileAndTheCauses(): void
    {
        $root = $this->factory->project->path;
        $this->factory->processes->willSucceed(self::AFFECTED)->willSucceed(\sprintf(self::CLEAN_DUMP_SRC_ONLY, $root));

        new OpcacheTool()->run($this->factory->context());

        self::assertSame(
            "OPcache produced no optimizer dump for these files, so nothing verified them:\n"
            . '  ' . $root . "/tests/ATest.php\n"
            . "\n"
            . "OPcache skips a file it will not cache: an opcache.blacklist_filename entry, or\n"
            . "exhausted shared memory (raise opcache.memory_consumption). Clear the cause and re-run.\n",
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aFindingPrintsEachComparisonTheWholeFixAndTheIdentifier(): void
    {
        $root = $this->factory->project->path;
        $this->factory->processes->willSucceed(self::AFFECTED)->willSucceed(\sprintf(self::BAD_DUMP, $root));

        new OpcacheTool()->run($this->factory->context());

        self::assertSame(
            "The optimizer left these comparisons with two constant operands (file:lines  function  opcode):\n"
            . '  ' . $root . "/src/A.php:12-20  App\\Gateway::find  IS_NOT_IDENTICAL null array(...)\n"
            . <<<'TEXT'

                HOW TO FIX
                ----------
                The VM has no handler for a constant-vs-constant comparison: it segfaults or compares
                garbage. The optimizer produces one when an earlier check on the same path proves a
                variable is a constant (usually `null === $x`) and a later comparison of that variable
                with a literal (usually `[] !== $x`) cannot be folded. Rewrite the condition so the
                second comparison does not sit on the branch where the first has already decided the
                value, e.g. `null === $x || [] === $x` in that order, and re-run this lane.
                Hosts that must run this PHP should also set opcache.optimization_level=0x7FFEBFDF.

                🪪  phpqaci.opcache  (vendor/bin/rule-doc phpqaci.opcache)

                TEXT,
            $this->factory->output->fetch(),
        );
    }

    /**
     * Files go to the dump script 200 at a time. A finding in the first batch, and the files the
     * first batch dumped, still count once the second batch has run.
     */
    #[Test]
    public function findingsAndDumpedFilesAreKeptAcrossBatches(): void
    {
        $root  = $this->factory->project->path;
        $extra = [];
        for ($i = 0; $i < 201; ++$i) {
            $file = \sprintf('src/F%03d.php', $i);
            $this->factory->project->write($file, self::PHP_STUB);
            $extra[] = $root . '/' . $file;
        }

        // tests/ATest.php, src/A.php, then src/F000.php ... src/F200.php: 203 files, so the
        // first batch ends at src/F197.php and the second holds the last three.
        $first = \sprintf(self::BAD_DUMP, $root) . "\n\n" . $this->cleanDump(...\array_slice($extra, 0, 198));
        $this->factory->processes
            ->willSucceed(self::AFFECTED)
            ->willSucceed($first)
            ->willSucceed($this->cleanDump(...\array_slice($extra, 198)))
        ;

        $result = new OpcacheTool()->run($this->factory->context());

        self::assertCount(3, $this->factory->processes->specs);
        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 comparison(s) the optimizer left as constant-vs-constant', $result->summary);
    }

    /** An ignored path that names a file leaves that file out; one that only shares its prefix does not. */
    #[Test]
    public function anIgnoredPathMatchesAFileOrADirectoryButNotANamePrefix(): void
    {
        $root = $this->factory->project->path;
        $this->factory->processes->willSucceed(self::AFFECTED)->willSucceed(\sprintf(self::CLEAN_DUMP_SRC_ONLY, $root));
        $config = $this->factory->builder()->withIgnoredPaths('tests/ATest.php', 'src/A')->build();

        $result = new OpcacheTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([$root . '/' . self::SRC_A], $this->compiledFiles());
    }

    /** Only regular .php files are compiled, each directory's in name order. */
    #[Test]
    public function onlyRegularPhpFilesAreCompiledInNameOrder(): void
    {
        $root = $this->factory->project->path;
        foreach (['src/E.php', 'src/C.php', 'src/D.php', 'src/B.php'] as $file) {
            $this->factory->project->write($file, self::PHP_STUB);
        }

        $this->factory->project->write('src/notes.txt', 'not PHP');
        \Safe\symlink($root . '/src/Missing.php', $root . '/src/Dangling.php');
        $this->factory->processes->willSucceed(self::AFFECTED)->willFail(255);

        new OpcacheTool()->run($this->factory->context());

        self::assertSame(
            [$root . '/' . self::TESTS_A, $root . '/' . self::SRC_A, $root . '/src/B.php', $root . '/src/C.php', $root . '/src/D.php', $root . '/src/E.php'],
            $this->compiledFiles(),
        );
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new OpcacheTool();

        self::assertSame('opcache', $tool->name());
        self::assertSame('phpqaci.opcache', $tool->identifier());
        self::assertSame(OpcacheDefects::IDENTIFIER, $tool->identifier());
    }

    /** A clean optimizer dump of the given files, one `$_main` block each. */
    private function cleanDump(string ...$files): string
    {
        return implode("\n\n", array_map(
            static fn (string $file): string => "\$_main:\n     ; (lines=1, args=0, vars=0, tmps=0)\n     ; (after optimizer)\n     ; " . $file . ":1-9\n0000 RETURN int(1)",
            $files,
        ));
    }

    /** @return list<string> the files handed to the dump script, in order */
    private function compiledFiles(): array
    {
        $command = $this->factory->processes->specs[1]->command;

        return \array_slice($command, (int)array_search('--', $command, true) + 1);
    }
}
