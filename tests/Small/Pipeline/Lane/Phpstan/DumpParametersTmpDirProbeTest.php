<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Lane\Phpstan\DumpParametersTmpDirProbe;
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
 * @internal
 */
#[CoversClass(DumpParametersTmpDirProbe::class)]
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
final class DumpParametersTmpDirProbeTest extends TestCase
{
    private const string CONFIG = '/project/qaConfig/phpstan.neon';

    private const string PROBE_TEMP = '/probe-temp';

    private const string DEFAULT_TMP_DIR = self::PROBE_TEMP . '/phpstan';

    private const string PROJECT_TMP_DIR = '/ci-cache/phpstan';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    /**
     * PHPStan is asked for its merged parameters through the phar, Xdebug off, from the project
     * root. Its TMPDIR is a directory of the probe's own under the QA cache: the dump compiles
     * PHPStan's container into tmpDir, which by default is the system temp directory every
     * checkout on the host shares, the very place the lanes keep PHPStan out of.
     */
    #[Test]
    public function itDumpsTheParametersThroughThePharWithATemporaryDirectoryOfItsOwn(): void
    {
        $this->factory->processes->willSucceed($this->dump(self::PROJECT_TMP_DIR, self::PROBE_TEMP));
        $context = $this->factory->context();
        $paths   = $context->config->paths;

        new DumpParametersTmpDirProbe()->projectTmpDir($context, self::CONFIG, null);

        $spec  = $this->factory->processes->lastSpec();
        $probe = $paths->cacheDir . '/' . DumpParametersTmpDirProbe::TEMP_DIR;
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-f', $paths->pharDir . '/phpstan.phar', '--', 'dump-parameters', '-c', self::CONFIG, '--json'],
            $spec->command,
        );
        self::assertSame($paths->projectRoot, $spec->cwd);
        self::assertSame(['TMPDIR' => $probe, 'XDEBUG_MODE' => 'off'], $spec->env);
        self::assertFalse($spec->streamOutput, 'the JSON is read, not shown');
        self::assertDirectoryExists($probe);
    }

    /** The deadCode lane loads its detector with --autoload-file; the dump gets the same file. */
    #[Test]
    public function anAutoloadFileIsPassedOn(): void
    {
        $this->factory->processes->willSucceed($this->dump(self::PROJECT_TMP_DIR, self::PROBE_TEMP));

        new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, '/detector/vendor/autoload.php');

        self::assertSame(
            ['dump-parameters', '-c', self::CONFIG, '--json', '--autoload-file', '/detector/vendor/autoload.php'],
            \array_slice($this->factory->processes->lastSpec()->command, 6),
        );
    }

    #[Test]
    public function anExistingTemporaryDirectoryIsKept(): void
    {
        $this->factory->project->write('var/qa/cache/' . DumpParametersTmpDirProbe::TEMP_DIR . '/phpstan/kept', 'x');
        $this->factory->processes->willSucceed($this->dump(self::PROJECT_TMP_DIR, self::PROBE_TEMP));

        new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, null);

        self::assertFileExists($this->factory->project->path . '/var/qa/cache/' . DumpParametersTmpDirProbe::TEMP_DIR . '/phpstan/kept');
    }

    #[Test]
    public function aTmpDirOtherThanPhpstansDefaultIsTheProjects(): void
    {
        $this->factory->processes->willSucceed($this->dump(self::PROJECT_TMP_DIR, self::PROBE_TEMP));

        self::assertSame(self::PROJECT_TMP_DIR, new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, null));
    }

    /** The default is `%sysGetTempDir%/phpstan`, read from the same dump, so it is the child's own. */
    #[Test]
    public function phpstansDefaultTmpDirIsNotTheProjects(): void
    {
        $this->factory->processes->willSucceed($this->dump(self::DEFAULT_TMP_DIR, self::PROBE_TEMP));

        self::assertNull(new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, null));
    }

    /** A directory beside the default, sharing its prefix, is a choice and not the default. */
    #[Test]
    public function aTmpDirThatOnlyStartsLikeTheDefaultIsTheProjects(): void
    {
        $this->factory->processes->willSucceed($this->dump(self::DEFAULT_TMP_DIR . '-ci', self::PROBE_TEMP));

        self::assertSame(self::DEFAULT_TMP_DIR . '-ci', new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, null));
    }

    /**
     * A failed dump is no answer: the analyse run reports a broken configuration itself, so the
     * probe only declines to say rather than failing the lane.
     */
    #[Test]
    public function aFailedDumpGivesNoAnswerEvenWithJsonOnStdout(): void
    {
        $json = $this->dump(self::PROJECT_TMP_DIR, self::PROBE_TEMP);
        $this->factory->processes->willReturn(new ProcessResultDto(1, $json, $json));

        self::assertNull(new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, null));
    }

    #[Test]
    #[DataProvider('unusableAnswers')]
    public function anUnusableAnswerGivesNoAnswer(string $stdout): void
    {
        $this->factory->processes->willSucceed($stdout);

        self::assertNull(new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, null));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableAnswers(): iterable
    {
        yield 'not JSON' => ['No rules detected'];
        yield 'empty' => [''];
        yield 'JSON that is not an object' => ['"/ci-cache/phpstan"'];
        yield 'no tmpDir' => [\Safe\json_encode(['sysGetTempDir' => self::PROBE_TEMP])];
        yield 'no sysGetTempDir' => [\Safe\json_encode(['tmpDir' => self::PROJECT_TMP_DIR])];
        yield 'a tmpDir that is not a string' => [\Safe\json_encode(['tmpDir' => ['x'], 'sysGetTempDir' => self::PROBE_TEMP])];
        yield 'a sysGetTempDir that is not a string' => [\Safe\json_encode(['tmpDir' => self::PROJECT_TMP_DIR, 'sysGetTempDir' => 1])];
    }

    /** Only stdout is parsed: a warning PHP writes to stderr does not cost the answer. */
    #[Test]
    public function stderrIsNotParsed(): void
    {
        $json = $this->dump(self::PROJECT_TMP_DIR, self::PROBE_TEMP);
        $this->factory->processes->willReturn(new ProcessResultDto(0, "PHP Warning: something\n" . $json, $json));

        self::assertSame(self::PROJECT_TMP_DIR, new DumpParametersTmpDirProbe()->projectTmpDir($this->factory->context(), self::CONFIG, null));
    }

    private function dump(string $tmpDir, string $sysGetTempDir): string
    {
        return \Safe\json_encode(['level' => 'max', 'tmpDir' => $tmpDir, 'sysGetTempDir' => $sysGetTempDir]);
    }
}
