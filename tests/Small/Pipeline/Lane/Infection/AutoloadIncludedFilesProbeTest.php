<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Lane\Infection\AutoloadIncludedFilesProbe;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The probe asks a PHP child to load the project's autoloader as PHPUnit's
 * runner does and to print what that included; these tests drive it with a
 * fake process runner. tests/Large/Infection/PreloadedSourceTest runs the real
 * script.
 *
 * @internal
 */
#[CoversClass(AutoloadIncludedFilesProbe::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class AutoloadIncludedFilesProbeTest extends TestCase
{
    private const string AUTOLOAD = '/project/vendor/autoload.php';

    private const array FILES = ['/lib/bin/autoload-included-files', self::AUTOLOAD, '/project/src/Loaded.php'];

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    /** The shipped script, Xdebug off, from the project root, its answer read rather than shown. */
    #[Test]
    public function itRunsTheShippedScriptOnTheAutoloaderFromTheProjectRoot(): void
    {
        $this->factory->processes->willSucceed($this->answer(...self::FILES));
        $context = $this->factory->context();
        $paths   = $context->config->paths;

        new AutoloadIncludedFilesProbe()->includedFiles($context, self::AUTOLOAD);

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-f', $paths->libraryRoot . '/bin/autoload-included-files', '--', self::AUTOLOAD],
            $spec->command,
        );
        self::assertFileExists($paths->libraryRoot . AutoloadIncludedFilesProbe::SCRIPT);
        self::assertSame($paths->projectRoot, $spec->cwd);
        self::assertSame(['XDEBUG_MODE' => 'off'], $spec->env);
        self::assertFalse($spec->streamOutput);
    }

    #[Test]
    public function theFilesAfterTheMarkerAreTheAnswer(): void
    {
        $this->factory->processes->willSucceed($this->answer(...self::FILES));

        self::assertSame(self::FILES, new AutoloadIncludedFilesProbe()->includedFiles($this->factory->context(), self::AUTOLOAD));
    }

    /** A files entry may print; only what follows the last marker is the script's. */
    #[Test]
    public function whatAFilesEntryPrintsBeforeTheAnswerIsIgnored(): void
    {
        $printed = "booting\n" . AutoloadIncludedFilesProbe::MARKER . "[\"/not/this\"]\n" . $this->answer(...self::FILES);
        $this->factory->processes->willSucceed($printed);

        self::assertSame(self::FILES, new AutoloadIncludedFilesProbe()->includedFiles($this->factory->context(), self::AUTOLOAD));
    }

    /** A warning on stderr is in the combined output, never in the answer. */
    #[Test]
    public function onlyStdoutIsRead(): void
    {
        $this->factory->processes->willReturn(new ProcessResultDto(0, 'PHP Warning: x' . $this->answer(...self::FILES), $this->answer(...self::FILES)));

        self::assertSame(self::FILES, new AutoloadIncludedFilesProbe()->includedFiles($this->factory->context(), self::AUTOLOAD));
    }

    /** An autoloader that cannot be loaded is one PHPUnit cannot load either: no answer, with what PHP said. */
    #[Test]
    public function aChildThatFailsGivesTheReasonWithItsOutput(): void
    {
        $this->factory->processes->willReturn(new ProcessResultDto(255, "\nPHP Fatal error:  Failed opening required\n", $this->answer(...self::FILES)));

        self::assertSame(
            "it exited 255:\nPHP Fatal error:  Failed opening required",
            new AutoloadIncludedFilesProbe()->includedFiles($this->factory->context(), self::AUTOLOAD),
        );
    }

    #[Test]
    #[DataProvider('unusableAnswers')]
    public function anUnusableAnswerGivesTheReason(string $stdout, string $reason): void
    {
        $this->factory->processes->willReturn(new ProcessResultDto(0, $stdout, $stdout));

        self::assertSame($reason, new AutoloadIncludedFilesProbe()->includedFiles($this->factory->context(), self::AUTOLOAD));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableAnswers(): iterable
    {
        $noList  = 'it printed no list of the files it included (a files entry that exits stops it before it can)';
        $notList = 'what it printed as the files it included is not a JSON list of paths';

        yield 'nothing' => ['', $noList];
        yield 'no marker' => ['["/project/src/Loaded.php"]', $noList];
        yield 'not JSON' => [AutoloadIncludedFilesProbe::MARKER . '/project/src/Loaded.php', $notList];
        yield 'a JSON object' => [AutoloadIncludedFilesProbe::MARKER . '{"a": "/project/src/Loaded.php"}', $notList];
        yield 'a JSON string' => [AutoloadIncludedFilesProbe::MARKER . '"/project/src/Loaded.php"', $notList];
        yield 'a list holding a number' => [AutoloadIncludedFilesProbe::MARKER . '["/project/src/Loaded.php", 1]', $notList];
    }

    private function answer(string ...$files): string
    {
        return AutoloadIncludedFilesProbe::MARKER . \Safe\json_encode($files, \JSON_UNESCAPED_SLASHES) . "\n";
    }
}
