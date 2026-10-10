<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;
use LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain;
use LTS\PHPQA\Pipeline\Lane\Phpstan\TmpDirNeon;
use LTS\PHPQA\Tests\Support\ContextFactory;
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
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class TmpDirNeonTest extends TestCase
{
    private const string PROJECT_NEON = 'qaConfig/phpstan.neon';

    private const string SHARED_NEON = 'shared.neon';

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
        $line = new TmpDirNeon()->forLane($this->factory->context(), 'Lane', 'lane');

        $dir = $this->factory->project->path . '/var/qa/cache/lane';
        self::assertSame("    tmpDir: '" . $dir . "'\n", $line);
        self::assertDirectoryExists($dir);
        self::assertSame("Lane: cache in var/qa/cache/lane\n", $this->factory->output->fetch());
    }

    #[Test]
    public function aLaneWritesNothingWhenTheProjectSetsTmpDirAndSaysWhere(): void
    {
        $this->factory->project->write(self::PROJECT_NEON, "parameters:\n    tmpDir: /ci-cache/phpstan\n");

        self::assertSame('', new TmpDirNeon()->forLane($this->factory->context(), 'Lane', 'lane'));
        self::assertDirectoryDoesNotExist($this->factory->project->path . '/var/qa/cache/lane');
        self::assertSame("Lane: cache in the tmpDir qaConfig/phpstan.neon sets\n", $this->factory->output->fetch());
    }

    #[Test]
    public function theLineIsAQuotedAbsolutePathAndTheDirectoryIsCreated(): void
    {
        $dir = $this->factory->project->path . "/var/qa/cache/it's here";

        self::assertSame("    tmpDir: '" . $this->factory->project->path . "/var/qa/cache/it''s here'\n", new TmpDirNeon()->parameters($dir));
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

    #[Test]
    public function aMissingConfigDeclaresNone(): void
    {
        self::assertNull(new TmpDirNeon()->declaredBy($this->factory->project->path . '/nope.neon', $this->factory->project->path));
    }
}
