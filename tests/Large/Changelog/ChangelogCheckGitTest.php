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
use LTS\PHPQA\Tests\Support\GitSandbox;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The changelog check against real git history: a bare origin whose default
 * branch is php8.5, a clone, and the commits a feature branch and a release
 * would make. The Small tests pin each git call's argv; these prove the calls
 * mean what the check assumes they mean.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ChangelogCheckGitTest extends TestCase
{
    private const string COMPOSER = "{\n  \"name\": \"fixture/changelog\",\n  \"require\": {\n    \"php\": \"^8.5\"\n  }\n}\n";

    private const string CHANGELOG = "# Changelog\n\n## Unreleased\n\n";

    private const string WITH_FIX = "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A fix.\n";

    private const string SOURCE = 'src/A.php';

    private const string FEATURE = 'feature/x';

    private const string CHANGELOG_FILE = 'CHANGELOG.md';

    private const string PHP_FILE = "<?php\n";

    private const string CHECKOUT = 'checkout';

    private const string TEST_FILE = 'tests/ATest.php';

    private const string TIDY_TESTS = 'Tidy the tests';

    private const string CO_AUTHOR ='Co-Authored-By: Sandbox <sandbox@example.invalid>';

    private GitSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create([
            'composer.json'      => self::COMPOSER,
            self::CHANGELOG_FILE => self::CHANGELOG,
            self::SOURCE         => self::PHP_FILE,
            self::TEST_FILE      => self::PHP_FILE,
        ]);
        $this->sandbox->git('tag', '85.0.0');
        $this->sandbox->git(self::CHECKOUT, '-b', self::FEATURE);
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function aWatchedChangeWithoutAnEntryFailsAndAddingOnePasses(): void
    {
        $this->sandbox->commit(self::SOURCE, "<?php\n// changed\n");

        $failed = $this->check();
        self::assertCount(1, $failed->problems);
        self::assertStringContainsString('1 watched file changed since the merge base with origin/php8.5', $failed->problems[0]);
        self::assertStringContainsString('    src/A.php', $failed->problems[0]);

        $this->sandbox->commit(self::CHANGELOG_FILE, self::WITH_FIX);

        $passed = $this->check();
        self::assertSame([], $passed->problems, implode("\n", $passed->problems));
        self::assertSame('1 watched file changed; recorded by 1 new "## Unreleased" entry.', $passed->report[2]);
    }

    #[Test]
    public function anUncommittedChangeIsJudgedToo(): void
    {
        $this->sandbox->root->write('work/src/New.php', self::PHP_FILE);

        self::assertStringContainsString('    src/New.php', $this->check()->problems[0]);
    }

    #[Test]
    public function aValidTrailerStandsInForAnEntryAndABareOneDoesNot(): void
    {
        $this->sandbox->commit(self::SOURCE, "<?php\n// bare\n", 'Refactor', 'Changelog: none');
        self::assertStringContainsString('Ignored for want of a reason: "none"', $this->check()->problems[0]);

        $this->sandbox->commit(self::SOURCE, "<?php\n// reasoned\n", 'Refactor', 'Changelog: none — internal refactor only');
        self::assertTrue($this->check()->passed());
    }

    #[Test]
    public function aChangelogLineAboveTheFinalTrailerBlockIsReportedByCommitWhateverItTouches(): void
    {
        $this->sandbox->commit(self::SOURCE, "<?php\n// placed\n", 'Rename internals', "Changelog: none — internal refactor only\n" . self::CO_AUTHOR);
        $this->sandbox->commit(self::SOURCE, "<?php\n// misplaced, watched\n", 'Tidy the source', 'Changelog: none — misplaced above the trailers', self::CO_AUTHOR);

        $watchedCommit = $this->head();
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// misplaced, unwatched\n", self::TIDY_TESTS, 'Changelog: none — tests only, misplaced too', self::CO_AUTHOR);
        $unwatchedCommit = $this->head();

        $result = $this->check();

        self::assertCount(1, $result->problems, implode("\n", $result->problems));
        $problem = $result->problems[0];
        self::assertStringContainsString('2 "Changelog:" lines in the range are not commit trailers', $problem);
        self::assertStringContainsString('    ' . substr($watchedCommit, 0, 12) . ' Changelog: none — misplaced above the trailers', $problem);
        self::assertStringContainsString('    ' . substr($unwatchedCommit, 0, 12) . ' Changelog: none — tests only, misplaced too', $problem);
        self::assertStringContainsString('move each line into the final trailer block', $problem);
        self::assertStringContainsString('git rebase -i --rebase-merges ', $problem);
        self::assertStringNotContainsString('internal refactor only', $problem);
    }

    #[Test]
    public function theWholeSubjectParagraphIsTheSubjectAsGitReadsItAndIsNotJudged(): void
    {
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// wrapped subject\n", "Fix the parser so the\nChangelog: lane reads every entry", 'Body prose.', self::CO_AUTHOR);
        self::assertSame("Fix the parser so the Changelog: lane reads every entry\n", $this->headAs('%s'));
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// subject paragraph\n", "Tidy the tests\nChangelog: none — tests only, in the subject paragraph");
        self::assertSame("\n", $this->headAs('%(trailers:key=Changelog)'));
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// subject prose\n", 'Changelog: file the entry for the fix');
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// below the subject\n", self::TIDY_TESTS, 'Changelog: none — tests only, below the subject', self::CO_AUTHOR);

        $commit = $this->head();

        $problem = $this->onlyProblem();

        self::assertStringContainsString('1 "Changelog:" line in the range is not a commit trailer', $problem);
        self::assertStringContainsString('    ' . substr($commit, 0, 12) . ' Changelog: none — tests only, below the subject', $problem);
    }

    #[Test]
    public function aMessageThatBeginsWithABlankLineHasGitsSubjectAndBodyJudgedAsGitReadsThem(): void
    {
        $this->commitVerbatim("\nChangelog: tidy the entry for the fix\n\nBody prose.\n");
        self::assertSame("Changelog: tidy the entry for the fix\n", $this->headAs('%s'));
        $this->commitVerbatim("\n\nTidy the tests\nChangelog: none — wrapped after leading blank lines\n\nBody prose.\n");
        $this->commitVerbatim("\nTidy the tests\n\nChangelog: none — tests only, after a leading blank line\n\n" . self::CO_AUTHOR . "\n");
        $commit = $this->head();

        $problem = $this->onlyProblem();

        self::assertStringContainsString('1 "Changelog:" line in the range is not a commit trailer', $problem);
        self::assertStringContainsString('    ' . substr($commit, 0, 12) . ' Changelog: none — tests only, after a leading blank line', $problem);
    }

    #[Test]
    public function aLowerCaseOrSpacedChangelogLineAboveTheBlockIsReported(): void
    {
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// variants\n", self::TIDY_TESTS, "changelog: none — lower case, misplaced\nChangelog : none — spaced, misplaced", self::CO_AUTHOR);
        $commit = $this->head();

        $problem = $this->onlyProblem();

        self::assertStringContainsString('2 "Changelog:" lines in the range are not commit trailers', $problem);
        self::assertStringContainsString('    ' . substr($commit, 0, 12) . ' changelog: none — lower case, misplaced', $problem);
        self::assertStringContainsString('    ' . substr($commit, 0, 12) . ' Changelog : none — spaced, misplaced', $problem);
    }

    #[Test]
    public function aShorterChangelogLineAboveTheBlockIsTheOneNamed(): void
    {
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// prefix\n", self::TIDY_TESTS, 'Changelog: none', "Changelog: none — tests only, placed\n" . self::CO_AUTHOR);
        $commit = $this->head();

        $problem = $this->onlyProblem();

        self::assertStringContainsString('    ' . substr($commit, 0, 12) . " Changelog: none\n", $problem);
        self::assertStringNotContainsString('tests only, placed', $problem);
    }

    #[Test]
    public function onTheDefaultBranchAMisplacedChangelogLineIsReportedWithoutFailing(): void
    {
        $this->sandbox->git(self::CHECKOUT, GitSandbox::DEFAULT_BRANCH);
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// merged already\n", self::TIDY_TESTS, 'Changelog: none — tests only, misplaced here', self::CO_AUTHOR);

        $commit = $this->head();

        $result = $this->check();

        self::assertTrue($result->passed(), implode("\n", $result->problems));
        self::assertContains('1 "Changelog:" line since the last release is not a commit trailer; history already on the default branch is not rewritten, so it is reported, not failed:', $result->report);
        self::assertContains('    ' . substr($commit, 0, 12) . ' Changelog: none — tests only, misplaced here', $result->report);
    }

    #[Test]
    public function aTestOnlyChangeNeedsNoEntry(): void
    {
        $this->sandbox->commit(self::TEST_FILE, "<?php\n// more\n");

        $result = $this->check();

        self::assertTrue($result->passed(), implode("\n", $result->problems));
        self::assertSame('1 file changed, none of them watched (src/, composer.json): no entry needed.', $result->report[2]);
    }

    #[Test]
    public function aNewRequirementNeedsABreakingEntry(): void
    {
        $this->sandbox->commit('composer.json', str_replace('"php": "^8.5"', "\"php\": \"^8.5\",\n    \"ext-intl\": \"*\"", self::COMPOSER));
        $this->sandbox->commit(self::CHANGELOG_FILE, self::WITH_FIX);

        $failed = $this->check();
        self::assertCount(1, $failed->problems);
        self::assertStringContainsString('(ext-intl * added)', $failed->problems[0]);

        $this->sandbox->commit(self::CHANGELOG_FILE, self::WITH_FIX . "\n### Changed — breaking\n\n- Requires ext-intl.\n");
        self::assertTrue($this->check()->passed());
    }

    #[Test]
    public function theDefaultBranchIsJudgedFromTheLatestReleaseTag(): void
    {
        $this->sandbox->git(self::CHECKOUT, GitSandbox::DEFAULT_BRANCH);
        $this->sandbox->commit(self::SOURCE, "<?php\n// on the default branch\n");

        $failed = $this->check();
        self::assertSame('Range: since the last release tag 85.0.0.', $failed->report[1]);
        self::assertCount(1, $failed->problems);

        $this->sandbox->commit(self::CHANGELOG_FILE, self::WITH_FIX);
        self::assertTrue($this->check()->passed());
    }

    #[Test]
    public function aShallowCloneFailsInsteadOfPassingOnNoEvidence(): void
    {
        $shallow = $this->sandbox->root->path . '/shallow';
        $this->sandbox->git('clone', '--depth', '1', 'file://' . $this->sandbox->origin, $shallow);

        $result = $this->check($shallow);

        self::assertFalse($result->passed());
        self::assertStringContainsString('this is a shallow clone', $result->problems[0]);
    }

    #[Test]
    public function aDefaultBranchWithNoReleaseTagFails(): void
    {
        $this->sandbox->git('tag', '-d', '85.0.0');
        $this->sandbox->git(self::CHECKOUT, GitSandbox::DEFAULT_BRANCH);

        self::assertStringContainsString('no 85.N.N release tag is in this clone', $this->check()->problems[0]);
    }

    private function onlyProblem(): string
    {
        $problems = $this->check()->problems;
        self::assertCount(1, $problems, implode("\n", $problems));

        return $problems[0];
    }

    /** Commit a test-file change with $message kept byte for byte, as `git commit-tree` would, leading blank lines included. */
    private function commitVerbatim(string $message): void
    {
        $this->sandbox->root->write('work/' . self::TEST_FILE, "<?php\n// " . str_replace("\n", ' ', $message) . "\n");
        $this->sandbox->git('add', '-A');
        $this->sandbox->git('commit', '--cleanup=verbatim', '-m', $message);
    }

    /** The HEAD commit as git formats it: git's own reading of the message, to compare the check with. */
    private function headAs(string $format): string
    {
        return $this->sandbox->git('log', '-1', '--format=' . $format);
    }

    private function head(): string
    {
        return trim($this->sandbox->git('rev-parse', 'HEAD'));
    }

    private function check(?string $root = null): ChangelogCheckResultDto
    {
        $root ??= $this->sandbox->work;
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
}
