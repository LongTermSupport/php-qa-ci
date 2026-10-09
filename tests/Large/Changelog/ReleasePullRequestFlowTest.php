<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Changelog;

use LTS\PHPQA\Changelog\ChangelogCheck;
use LTS\PHPQA\Changelog\ChangelogGit;
use LTS\PHPQA\Changelog\Dto\ChangelogCheckResultDto;
use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use LTS\PHPQA\Changelog\WatchedPaths;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\ChildEnvironment;
use LTS\PHPQA\Tests\Support\GitSandbox;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

/**
 * The release as .github/workflows/release.yml runs it, against real git: a
 * change lands on php8.5 with its entry, a release branch moves the entry into
 * a version section, the branch is merged with a merge commit, and only then is
 * the tag cut. Between the merge and the tag the lane must still pass on
 * php8.5, and `pending-tags` must name the commit that wrote the section.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ReleasePullRequestFlowTest extends TestCase
{
    private const string BIN = __DIR__ . '/../../../bin/changelog-release';

    private const string COMPOSER = "{\n  \"require\": {\n    \"php\": \"^8.5\"\n  }\n}\n";

    private const string CHANGELOG_FILE = 'CHANGELOG.md';

    private const string SOURCE = 'src/A.php';

    private const string RELEASE_BRANCH = 'chore/release-php8.5';

    private const string VERSION = '85.1.0';

    private const string PENDING_TAGS = 'pending-tags';

    private const string CHECKOUT = 'checkout';

    private GitSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create([
            'composer.json'      => self::COMPOSER,
            self::CHANGELOG_FILE => "# Changelog\n\n## Unreleased\n",
            self::SOURCE         => "<?php\n",
            'qaConfig/qa.php'    => '<?php
return static fn (\LTS\PHPQA\Pipeline\Config\QaConfigBuilder $qa) => $qa->withReleaseVersionPolicy(' . ReleaseVersionPolicy::class . '::lockedMajorFromPhpRequirement());
',
        ]);
        $this->sandbox->git('tag', '85.0.0');
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function theMergedReleasePassesTheLaneAndIsTaggedAtTheCommitThatWroteIt(): void
    {
        $this->sandbox->commit(self::SOURCE, "<?php\n// changed\n");
        $this->sandbox->commit(self::CHANGELOG_FILE, "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A fix.\n");

        $releaseCommit = $this->release();

        $merged = $this->check();
        self::assertSame([], $merged->problems, implode("\n", $merged->problems));
        self::assertSame('Range: since the last release tag 85.0.0.', $merged->report[1]);
        self::assertSame('1 watched file changed; recorded by 1 new "## Unreleased" entry.', $merged->report[2]);

        $pending = $this->cli(self::PENDING_TAGS);
        self::assertSame(0, $pending->getExitCode(), $pending->getErrorOutput());
        self::assertSame(self::VERSION . ' ' . $releaseCommit . "\n", $pending->getOutput());

        $this->sandbox->git('tag', self::VERSION, $releaseCommit);
        self::assertSame('', $this->cli(self::PENDING_TAGS)->getOutput());

        $this->sandbox->commit(self::SOURCE, "<?php\n// after the release\n");
        $after = $this->check();
        self::assertSame('Range: since the last release tag 85.1.0.', $after->report[1]);
        self::assertCount(1, $after->problems);
    }

    #[Test]
    public function aBreakingEntryReleasedButNotYetTaggedStillCoversANewRequirement(): void
    {
        $this->sandbox->commit('composer.json', str_replace('"php": "^8.5"', "\"php\": \"^8.5\",\n    \"ext-intl\": \"*\"", self::COMPOSER));
        $this->sandbox->commit(self::CHANGELOG_FILE, "# Changelog\n\n## Unreleased\n\n### Changed — breaking\n\n- Requires ext-intl.\n");
        $this->release();

        $result = $this->check();

        self::assertSame([], $result->problems, implode("\n", $result->problems));
    }

    #[Test]
    public function aReleaseSectionThatIsNotCommittedHasNothingToTag(): void
    {
        $this->sandbox->commit(self::CHANGELOG_FILE, "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A fix.\n");
        $this->cli('apply', self::VERSION, '2026-10-09');

        $pending = $this->cli(self::PENDING_TAGS);

        self::assertSame(1, $pending->getExitCode());
        self::assertSame('', $pending->getOutput());
        self::assertStringContainsString('no commit reachable from HEAD wrote "## 85.1.0 — 2026-10-09" into CHANGELOG.md', $pending->getErrorOutput());
    }

    /** Release on a branch, merge it into php8.5 with a merge commit; the release commit's sha. */
    private function release(): string
    {
        $this->sandbox->git(self::CHECKOUT, '-b', self::RELEASE_BRANCH);
        $apply = $this->cli('apply', self::VERSION, '2026-10-09');
        self::assertSame(0, $apply->getExitCode(), $apply->getErrorOutput());
        $this->sandbox->git('commit', '-am', 'Release ' . self::VERSION);
        $releaseCommit = trim($this->sandbox->git('rev-parse', 'HEAD'));

        $this->sandbox->git(self::CHECKOUT, GitSandbox::DEFAULT_BRANCH);
        $this->sandbox->git('merge', '--no-ff', '-m', 'Merge the release', self::RELEASE_BRANCH);

        return $releaseCommit;
    }

    private function check(): ChangelogCheckResultDto
    {
        $root      = $this->sandbox->work;
        $processes = new SymfonyProcessRunner(new NullOutput());

        return new ChangelogCheck()->check(
            $root,
            new ChangelogGit($processes, $root),
            new GitBranches($processes, $root),
            new EnvironmentReader([]),
            new WatchedPaths('src/', 'composer.json'),
            ReleaseVersionPolicy::lockedMajorFromPhpRequirement(),
        );
    }

    private function cli(string ...$arguments): Process
    {
        $process = new Process(['php', \Safe\realpath(self::BIN), ...array_values($arguments)], $this->sandbox->work, ChildEnvironment::withoutXdebug(GitSandbox::environment()));
        $process->run();

        return $process;
    }
}
