<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Runner\GitTrackedTree;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GitTrackedTree::class)]
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
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(\LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboManifest::class)]
#[Small]
final class GitTrackedTreeTest extends TestCase
{
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
    public function itAsksGitForTheTrackedFilesThatDifferWithoutTakingTheIndexLock(): void
    {
        $this->factory->processes->willSucceed('');

        new GitTrackedTree()->dirtyFiles($this->factory->context());

        $spec = $this->factory->processes->specs[0] ?? null;
        self::assertNotNull($spec);
        self::assertSame(['git', 'status', '--porcelain=v1', '-z', '--untracked-files=no', '--no-renames'], $spec->command);
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertSame('0', $spec->env['GIT_OPTIONAL_LOCKS'] ?? null, 'a status that refreshed the index would itself write a tracked-tree file');
        self::assertFalse($spec->streamOutput);
    }

    #[Test]
    public function aCleanTreeHasNoDirtyFiles(): void
    {
        $this->factory->processes->willSucceed('');

        self::assertSame([], new GitTrackedTree()->dirtyFiles($this->factory->context()));
    }

    #[Test]
    public function eachDirtyFileIsKeyedByItsPathAndStateAndTracksItsContent(): void
    {
        $this->factory->project->write('config/reference.php', 'one');
        $this->factory->project->write('src/Other.php', 'other');
        $porcelain = " M config/reference.php\0M  src/Other.php\0";
        $this->factory->processes->willSucceed($porcelain)->willSucceed($porcelain);

        $first = new GitTrackedTree()->dirtyFiles($this->factory->context());
        $this->factory->project->write('config/reference.php', 'two');
        $second = new GitTrackedTree()->dirtyFiles($this->factory->context());

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(['config/reference.php', 'src/Other.php'], array_keys($first));
        self::assertNotSame($first['config/reference.php'], $second['config/reference.php'], 'rewritten content is a different state');
        self::assertSame($first['src/Other.php'], $second['src/Other.php'], 'untouched content is the same state');
    }

    #[Test]
    public function aDeletedTrackedFileIsDirtyAndHasNoContentToHash(): void
    {
        $this->factory->processes->willSucceed(" D gone.php\0");

        $state = new GitTrackedTree()->dirtyFiles($this->factory->context());

        self::assertIsArray($state);
        self::assertArrayHasKey('gone.php', $state);
    }

    #[Test]
    public function aNameWithSpacesSurvives(): void
    {
        $this->factory->project->write('docs/a file.md', 'x');
        $this->factory->processes->willSucceed(" M docs/a file.md\0");

        $state = new GitTrackedTree()->dirtyFiles($this->factory->context());

        self::assertIsArray($state);
        self::assertSame(['docs/a file.md'], array_keys($state));
    }

    #[Test]
    public function gitFailingMeansNoAnswer(): void
    {
        $this->factory->processes->willFail(128, 'fatal: not a git repository');

        self::assertNull(new GitTrackedTree()->dirtyFiles($this->factory->context()));
    }
}
