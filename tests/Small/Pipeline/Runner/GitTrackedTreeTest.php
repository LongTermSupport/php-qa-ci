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
    private const string REFERENCE_FILE = 'config/reference.php';

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
        self::assertSame(['git', 'diff', '--relative', '--name-only', '-z', '--no-renames', 'HEAD'], $spec->command);
        self::assertSame($this->factory->project->path, $spec->cwd, 'git names files from here and leaves out what is outside it');
        self::assertSame('0', $spec->env['GIT_OPTIONAL_LOCKS'] ?? null, 'a comparison that refreshed the index would itself write a tracked-tree file');
        self::assertFalse($spec->streamOutput);
    }

    #[Test]
    public function aCleanTreeHasNoDirtyFiles(): void
    {
        $this->factory->processes->willSucceed('');

        self::assertSame([], new GitTrackedTree()->dirtyFiles($this->factory->context()));
    }

    #[Test]
    public function eachDirtyFileIsKeyedByItsPathAndTracksItsContent(): void
    {
        $this->factory->project->write(self::REFERENCE_FILE, 'one');
        $this->factory->project->write('src/Other.php', 'other');

        $names = self::REFERENCE_FILE . "\0src/Other.php\0";
        $this->factory->processes->willSucceed($names)->willSucceed($names);

        $first = new GitTrackedTree()->dirtyFiles($this->factory->context());
        $this->factory->project->write(self::REFERENCE_FILE, 'two');
        $second = new GitTrackedTree()->dirtyFiles($this->factory->context());

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame([self::REFERENCE_FILE, 'src/Other.php'], array_keys($first));
        self::assertNotSame($first[self::REFERENCE_FILE], $second[self::REFERENCE_FILE], 'rewritten content is a different state');
        self::assertSame($first['src/Other.php'], $second['src/Other.php'], 'untouched content is the same state');
    }

    #[Test]
    public function aDeletedTrackedFileIsDirtyAndHasNoContentToHash(): void
    {
        $this->factory->processes->willSucceed("gone.php\0");

        $state = new GitTrackedTree()->dirtyFiles($this->factory->context());

        self::assertIsArray($state);
        self::assertArrayHasKey('gone.php', $state);
    }

    #[Test]
    public function aNameWithSpacesSurvives(): void
    {
        $this->factory->project->write('docs/a file.md', 'x');
        $this->factory->processes->willSucceed("docs/a file.md\0");

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
