<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;
use LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain;
use LTS\PHPQA\Pipeline\Lane\Phpstan\TmpDirNeon;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TmpDirNeon::class)]
#[UsesClass(NeonIncludeChain::class)]
#[UsesClass(NeonIncludeChainDto::class)]
#[UsesClass(NeonRecordFileDto::class)]
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
#[UsesClass(ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class TmpDirNeonTest extends TestCase
{
    private const string PROJECT_NEON = 'qaConfig/phpstan.neon';

    private const string SHARED_NEON = 'shared.neon';

    private const string LABEL = 'Lane';

    private const string LANE = 'lane';

    private const string TMP_DIR_LINE = "    tmpDir: '";

    private const string LANE_CACHE = '/var/qa/cache/lane';

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
    public function aLaneGetsItsOwnDirectoryUnderTheProjectCacheAndSaysSo(): void
    {
        $line = new TmpDirNeon()->forLane($this->factory->context(), self::LABEL, self::LANE);

        $dir = $this->factory->project->path . self::LANE_CACHE;
        self::assertSame(self::TMP_DIR_LINE . $dir . "'\n", $line);
        self::assertDirectoryExists($dir);
        self::assertSame("Lane: cache in var/qa/cache/lane\n", $this->factory->output->fetch());
    }

    /** A cache directory outside the project is named in full: there is no project-relative form of it. */
    #[Test]
    public function aCacheOutsideTheProjectIsNamedByItsAbsolutePath(): void
    {
        $elsewhere = TempDir::create('phpqa-elsewhere');

        try {
            $line = new TmpDirNeon()->forLane($this->contextWithCacheIn($elsewhere->path . '/cache'), self::LABEL, self::LANE);

            self::assertSame(self::TMP_DIR_LINE . $elsewhere->path . "/cache/lane'\n", $line);
            self::assertSame('Lane: cache in ' . $elsewhere->path . "/cache/lane\n", $this->factory->output->fetch());
        } finally {
            $elsewhere->remove();
        }
    }

    /**
     * A sibling whose name starts with the project's (`/x/proj-other` beside `/x/proj`) is not
     * inside the project, so its path is not cut down as if it were.
     */
    #[Test]
    public function aCacheInASiblingSharingTheProjectsNameIsNotTreatedAsInsideIt(): void
    {
        $root    = $this->factory->project->path;
        $sibling = TempDir::createUnder(\dirname($root), basename($root));

        try {
            self::assertStringStartsWith($root . '-', $sibling->path, 'the sibling shares the project root as a string prefix');

            new TmpDirNeon()->forLane($this->contextWithCacheIn($sibling->path . '/cache'), self::LABEL, self::LANE);

            self::assertSame('Lane: cache in ' . $sibling->path . "/cache/lane\n", $this->factory->output->fetch());
        } finally {
            $sibling->remove();
        }
    }

    /**
     * The directory is created with the default mode the umask narrows, as every other
     * directory PHPStan or the pipeline makes: no permission is added or taken away.
     */
    #[Test]
    public function theDirectoryIsCreatedWithTheUmaskDefaultMode(): void
    {
        $dir      = $this->factory->project->path . '/var/qa/cache/mode/nested';
        $previous = umask(0);

        try {
            new TmpDirNeon()->parameters($dir);
        } finally {
            umask($previous);
        }

        clearstatcache();
        self::assertSame(0o777, \Safe\fileperms($dir) & 0o7777);
        self::assertSame(0o777, \Safe\fileperms(\dirname($dir)) & 0o7777, 'the parents too');
    }

    #[Test]
    public function aLaneWritesNothingWhenTheProjectSetsTmpDirAndSaysWhere(): void
    {
        $this->factory->project->write(self::PROJECT_NEON, "parameters:\n    tmpDir: /ci-cache/phpstan\n");

        self::assertSame('', new TmpDirNeon()->forLane($this->factory->context(), self::LABEL, self::LANE));
        self::assertDirectoryDoesNotExist($this->factory->project->path . self::LANE_CACHE);
        self::assertSame("Lane: cache in the tmpDir qaConfig/phpstan.neon sets\n", $this->factory->output->fetch());
    }

    #[Test]
    public function theLineIsAQuotedAbsolutePathAndTheDirectoryIsCreated(): void
    {
        $dir = $this->factory->project->path . "/var/qa/cache/it's here";

        self::assertSame(self::TMP_DIR_LINE . $this->factory->project->path . "/var/qa/cache/it''s here'\n", new TmpDirNeon()->parameters($dir));
        self::assertDirectoryExists($dir);
    }

    #[Test]
    public function anExistingDirectoryIsKept(): void
    {
        $dir = $this->factory->project->mkdir('var/qa/cache/kept');
        $this->factory->project->write('var/qa/cache/kept/resultCache.php', '<?php return [];');

        new TmpDirNeon()->parameters($dir);

        self::assertFileExists($dir . '/resultCache.php');
    }

    #[Test]
    public function aConfigThatSetsNoTmpDirDeclaresNone(): void
    {
        $neon = $this->factory->project->write(self::PROJECT_NEON, "parameters:\n    level: max\n    foo:\n        tmpDir: /not/this/one\n");

        self::assertNull(new TmpDirNeon()->declaredBy($neon, $this->factory->project->path));
    }

    #[Test]
    public function aNeonWithNoParametersDeclaresNone(): void
    {
        $neon = $this->factory->project->write(self::PROJECT_NEON, "services: []\n");

        self::assertNull(new TmpDirNeon()->declaredBy($neon, $this->factory->project->path));
    }

    #[Test]
    public function theProjectConfigThatSetsTmpDirIsNamedRelativeToTheProject(): void
    {
        $neon = $this->factory->project->write(self::PROJECT_NEON, "parameters:\n    tmpDir: /ci-cache/phpstan\n");

        self::assertSame(self::PROJECT_NEON, new TmpDirNeon()->declaredBy($neon, $this->factory->project->path));
    }

    /** PHPStan merges includes before the including file, so a tmpDir set in an include counts too. */
    #[Test]
    public function aTmpDirSetInAnIncludedFileIsFound(): void
    {
        $this->factory->project->write(self::SHARED_NEON, "parameters:\n    tmpDir: ../cache\n");
        $neon = $this->factory->project->write(self::PROJECT_NEON, "includes:\n    - ../shared.neon\n");

        self::assertSame(self::SHARED_NEON, new TmpDirNeon()->declaredBy($neon, $this->factory->project->path));
    }

    /** The including file is merged last, so its tmpDir is the one PHPStan uses. */
    #[Test]
    public function theLastDeclarationInMergeOrderIsNamed(): void
    {
        $this->factory->project->write(self::SHARED_NEON, "parameters:\n    tmpDir: ../cache\n");
        $neon = $this->factory->project->write(self::PROJECT_NEON, "includes:\n    - ../shared.neon\nparameters:\n    tmpDir: /ci-cache\n");

        self::assertSame(self::PROJECT_NEON, new TmpDirNeon()->declaredBy($neon, $this->factory->project->path));
    }

    /**
     * An include built from a `%parameter%` cannot be followed outside PHPStan, so PHPStan is
     * asked for the merged value; a tmpDir other than its default is the project's.
     */
    #[Test]
    public function aTmpDirBehindAParameterIncludeIsLeftToTheProject(): void
    {
        $this->factory->project->write(self::PROJECT_NEON, "includes:\n    - %env.SHARED_CONFIG%/tmpdir.neon\n");
        $this->factory->processes->willSucceed(self::dump('/ci-cache/phpstan', '/probe'));

        self::assertSame('', new TmpDirNeon()->forLane($this->factory->context(), self::LABEL, self::LANE));
        self::assertDirectoryDoesNotExist($this->factory->project->path . self::LANE_CACHE);
    }

    /** A PHP configuration file is not NEON the walk can read; PHPStan's merged value still names it. */
    #[Test]
    public function aTmpDirSetInAPhpConfigIncludeIsLeftToTheProject(): void
    {
        $this->factory->project->write('qaConfig/tmpdir.php', "<?php\n\nreturn ['parameters' => ['tmpDir' => '/ci-cache/phpstan']];\n");
        $this->factory->project->write(self::PROJECT_NEON, "includes:\n    - tmpdir.php\n");
        $this->factory->processes->willSucceed(self::dump('/ci-cache/phpstan', '/probe'));

        self::assertSame('', new TmpDirNeon()->forLane($this->factory->context(), self::LABEL, self::LANE));
        self::assertDirectoryDoesNotExist($this->factory->project->path . self::LANE_CACHE);
    }

    #[Test]
    public function aMissingConfigDeclaresNone(): void
    {
        self::assertNull(new TmpDirNeon()->declaredBy($this->factory->project->path . '/nope.neon', $this->factory->project->path));
    }

    /** What `phpstan dump-parameters --json` prints, reduced to the two parameters read. */
    private static function dump(string $tmpDir, string $sysGetTempDir): string
    {
        return \Safe\json_encode(['level' => 'max', 'tmpDir' => $tmpDir, 'sysGetTempDir' => $sysGetTempDir]);
    }

    private function contextWithCacheIn(string $cacheDir): ToolContext
    {
        return $this->factory->context($this->factory->builder(paths: $this->factory->paths(cacheDir: $cacheDir))->build());
    }
}
