<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\GitProjectFiles;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The files a project holds, as git lists them: everything tracked, plus new files not yet
 * committed, minus what `.gitignore` excludes, and only what is still on disk.
 *
 * @internal
 */
#[CoversClass(GitProjectFiles::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
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
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class GitProjectFilesTest extends TestCase
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
    public function itAsksGitForTrackedAndUntrackedFilesGitDoesNotIgnore(): void
    {
        $this->factory->processes->willSucceed();

        new GitProjectFiles()->files($this->factory->context());

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $spec->command);
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertFalse($spec->streamOutput);
    }

    /** git lists what the index holds, which on a dirty tree can name a file that has been deleted. */
    #[Test]
    public function itListsOnlyTheFilesThatAreStillOnDisk(): void
    {
        $this->factory->project->write('a.php', '');
        $this->factory->project->write('tools/a/composer.json', '{}');
        $this->factory->processes->willSucceed("a.php\0tools/a/composer.json\0gone.php\0");

        $files = new GitProjectFiles()->files($this->factory->context());

        self::assertSame(['a.php', 'tools/a/composer.json'], $files);
    }

    #[Test]
    public function aDirectoryGitNamesIsNotAFile(): void
    {
        $this->factory->project->mkdir('submodule');
        $this->factory->processes->willSucceed("submodule\0");

        self::assertSame([], new GitProjectFiles()->files($this->factory->context()));
    }

    #[Test]
    public function itReturnsNullWhenGitCannotAnswer(): void
    {
        $this->factory->processes->willFail(128, 'fatal: not a git repository');

        self::assertNull(new GitProjectFiles()->files($this->factory->context()));
    }
}
