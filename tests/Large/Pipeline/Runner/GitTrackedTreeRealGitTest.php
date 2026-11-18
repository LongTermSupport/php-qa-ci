<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Runner\GitTrackedTree;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The tracked-tree reading against real git. The Small tests pin the argv and the parsing of canned
 * output; these prove the git call means what the guard assumes, above all when the project is a
 * subdirectory of a larger repository, where git names files from the repository root.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class GitTrackedTreeRealGitTest extends TestCase
{
    private const string SOURCE = 'src/A.php';

    private const string SIBLING = 'sibling/B.php';

    private const string SOURCE_IN_WORK_TREE = 'work/project/src/A.php';

    private const string PHP = "<?php\n";

    private GitSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create([
            'project/composer.json' => "{}\n",
            'project/src/A.php'     => self::PHP,
            'project/src/b c.php'   => self::PHP,
            self::SIBLING           => self::PHP,
        ]);
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function aCleanTreeHasNoDirtyFiles(): void
    {
        self::assertSame([], $this->dirty($this->project()));
    }

    #[Test]
    public function aFileRewrittenTwiceIsADifferentStateEachTimeWhenTheProjectIsASubdirectoryOfTheRepository(): void
    {
        $this->sandbox->root->write(self::SOURCE_IN_WORK_TREE, "<?php\n// one\n");
        $first = $this->dirty($this->project());

        $this->sandbox->root->write(self::SOURCE_IN_WORK_TREE, "<?php\n// two\n");
        $second = $this->dirty($this->project());

        self::assertSame([self::SOURCE], array_keys($first), 'named as the project knows it, not from the repository root');
        self::assertSame([self::SOURCE], array_keys($second));
        self::assertNotSame($first[self::SOURCE], $second[self::SOURCE], 'the content moved, and the guard must see that');
    }

    #[Test]
    public function aSiblingProjectsChangeInTheSameRepositoryIsNotThisProjects(): void
    {
        $this->sandbox->root->write('work/' . self::SIBLING, "<?php\n// not ours\n");

        self::assertSame([], $this->dirty($this->project()));
    }

    #[Test]
    public function aProjectAtTheRootOfTheRepositoryNamesItsFilesFromThere(): void
    {
        $this->sandbox->root->write(self::SOURCE_IN_WORK_TREE, "<?php\n// one\n");

        self::assertSame(['project/src/A.php'], array_keys($this->dirty($this->sandbox->work)));
    }

    #[Test]
    public function aFileWhoseNameHoldsASpaceIsNamedWhole(): void
    {
        $this->sandbox->root->write('work/project/src/b c.php', "<?php\n// changed\n");

        self::assertSame(['src/b c.php'], array_keys($this->dirty($this->project())));
    }

    #[Test]
    public function aDeletedTrackedFileIsDirty(): void
    {
        \Safe\unlink($this->project() . '/src/A.php');

        self::assertSame([self::SOURCE], array_keys($this->dirty($this->project())));
    }

    #[Test]
    public function aStagedChangeIsDirtyToo(): void
    {
        $this->sandbox->root->write(self::SOURCE_IN_WORK_TREE, "<?php\n// staged\n");
        $this->sandbox->git('add', 'project/src/A.php');

        self::assertSame([self::SOURCE], array_keys($this->dirty($this->project())));
    }

    #[Test]
    public function aNewUntrackedFileIsNotWatched(): void
    {
        $this->sandbox->root->write('work/project/src/New.php', self::PHP);

        self::assertSame([], $this->dirty($this->project()));
    }

    #[Test]
    public function aDirectoryThatIsNotAGitWorkTreeHasNoAnswer(): void
    {
        $outside = TempDir::create('phpqa-not-git');

        try {
            $outside->write('src/A.php', self::PHP);

            self::assertNull(new GitTrackedTree()->dirtyFiles(RealLaneContext::over($outside->path, true, new BufferedOutput())));
        } finally {
            $outside->remove();
        }
    }

    private function project(): string
    {
        return $this->sandbox->work . '/project';
    }

    /** @return array<array-key, string> */
    private function dirty(string $root): array
    {
        $state = new GitTrackedTree()->dirtyFiles(RealLaneContext::over($root, true, new BufferedOutput()));
        self::assertIsArray($state, 'git answered');

        return $state;
    }
}
