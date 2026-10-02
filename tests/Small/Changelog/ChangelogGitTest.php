<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\ChangelogGit;
use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ChangelogGit::class)]
#[CoversClass(ChangelogHistoryException::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[Small]
final class ChangelogGitTest extends TestCase
{
    private const string ROOT = '/project';

    private const string BASE = 'abc123';

    private const string REMOTE_BRANCH = 'origin/php8.5';

    private const string CHANGELOG_FILE = 'CHANGELOG.md';

    private FakeProcessRunner $processes;

    private ChangelogGit $git;

    protected function setUp(): void
    {
        $this->processes = new FakeProcessRunner();
        $this->git       = new ChangelogGit($this->processes, self::ROOT);
    }

    #[Test]
    public function tagsAreTheNonEmptyLinesOfTheTagList(): void
    {
        $this->processes->willSucceed("84.0.0\n85.0.0\n\n");

        self::assertSame(['84.0.0', '85.0.0'], $this->git->tags());
        self::assertSame(['git tag --list'], $this->processes->commandLines());
        $spec = $this->processes->lastSpec();
        self::assertSame(self::ROOT, $spec->cwd);
        self::assertFalse($spec->streamOutput);
    }

    #[Test]
    public function aFailingGitCommandIsAHistoryProblemNamingTheCommand(): void
    {
        $this->processes->willFail(128, 'fatal: not a git repository');

        $this->expectException(ChangelogHistoryException::class);
        $this->expectExceptionMessageIsOrContains('`git tag --list` failed (exit 128): fatal: not a git repository');

        $this->git->tags();
    }

    #[Test]
    public function aShallowCloneSaysSo(): void
    {
        $this->processes->willSucceed("true\n")->willSucceed("false\n")->willFail(128);

        self::assertTrue($this->git->isShallow());
        self::assertFalse($this->git->isShallow());
        self::assertFalse($this->git->isShallow());
        self::assertSame('git rev-parse --is-shallow-repository', $this->processes->commandLines()[0]);
    }

    #[Test]
    public function aRefResolvesOnlyToACommit(): void
    {
        $this->processes->willSucceed("abc\n")->willFail(1);

        self::assertTrue($this->git->resolves(self::REMOTE_BRANCH));
        self::assertFalse($this->git->resolves('origin/nope'));
        self::assertSame(['git rev-parse --verify --quiet \'origin/php8.5^{commit}\'', 'git rev-parse --verify --quiet \'origin/nope^{commit}\''], $this->processes->commandLines());
    }

    #[Test]
    public function theMergeBaseIsTheTrimmedShaOrNull(): void
    {
        $this->processes->willSucceed(self::BASE . "\n")->willFail(1);

        self::assertSame(self::BASE, $this->git->mergeBase(self::REMOTE_BRANCH));
        self::assertNull($this->git->mergeBase(self::REMOTE_BRANCH));
        self::assertSame('git merge-base HEAD origin/php8.5', $this->processes->commandLines()[0]);
    }

    #[Test]
    public function changedFilesAreTheDiffAgainstTheWorkingTreePlusUntrackedFilesOnce(): void
    {
        $this->processes->willSucceed("src/A.php\0CHANGELOG.md\0")->willSucceed("src/New.php\0src/A.php\0");

        self::assertSame(['src/A.php', self::CHANGELOG_FILE, 'src/New.php'], $this->git->changedFiles(self::BASE));
        self::assertSame([
            'git diff --name-only -z --no-renames abc123 --',
            'git ls-files -z --others --exclude-standard',
        ], $this->processes->commandLines());
    }

    #[Test]
    public function aFileAtARevisionIsItsContentsOrNullWhenAbsent(): void
    {
        $this->processes->willSucceed()->willSucceed("# Changelog\n")->willFail(128);

        self::assertSame("# Changelog\n", $this->git->fileAt(self::BASE, self::CHANGELOG_FILE));
        self::assertNull($this->git->fileAt(self::BASE, self::CHANGELOG_FILE));
        self::assertSame([
            'git cat-file -e abc123:CHANGELOG.md',
            'git show abc123:CHANGELOG.md',
            'git cat-file -e abc123:CHANGELOG.md',
        ], $this->processes->commandLines());
    }

    #[Test]
    public function aFileThatExistsButCannotBeShownIsAHistoryProblem(): void
    {
        $this->processes->willSucceed()->willFail(128, 'fatal: bad object');

        $this->expectException(ChangelogHistoryException::class);

        $this->git->fileAt(self::BASE, self::CHANGELOG_FILE);
    }

    #[Test]
    public function trailersAreTheChangelogValuesOfEveryCommitInTheRange(): void
    {
        $this->processes->willSucceed("none — tests only\n\n\nnone\n");

        self::assertSame(['none — tests only', 'none'], $this->git->trailers(self::BASE));
        self::assertSame(['git log \'--format=%(trailers:key=Changelog,valueonly)\' abc123..HEAD'], $this->processes->commandLines());
    }
}
