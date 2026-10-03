<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\ChangelogCheck;
use LTS\PHPQA\Changelog\ChangelogGit;
use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\ChangelogRangeResolver;
use LTS\PHPQA\Changelog\ChangelogTrailers;
use LTS\PHPQA\Changelog\ComposerRequirementChanges;
use LTS\PHPQA\Changelog\Dto\ChangelogCheckResultDto;
use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Dto\ChangelogRangeDto;
use LTS\PHPQA\Changelog\Dto\ChangelogTrailerVerdictDto;
use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;
use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\Changelog\ReleasedSections;
use LTS\PHPQA\Changelog\ReleaseLine;
use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use LTS\PHPQA\Changelog\WatchedPaths;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ChangelogCheck::class)]
#[CoversClass(ChangelogCheckResultDto::class)]
#[UsesClass(ChangelogGit::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[UsesClass(ChangelogParser::class)]
#[UsesClass(ChangelogRangeResolver::class)]
#[UsesClass(ChangelogTrailers::class)]
#[UsesClass(ComposerRequirementChanges::class)]
#[UsesClass(ChangelogDocumentDto::class)]
#[UsesClass(ChangelogHeadingBlockDto::class)]
#[UsesClass(ChangelogRangeDto::class)]
#[UsesClass(ChangelogTrailerVerdictDto::class)]
#[UsesClass(ChangelogHistoryException::class)]
#[UsesClass(ChangelogReleaseException::class)]
#[UsesClass(InvalidChangelogException::class)]
#[UsesClass(ReleaseLine::class)]
#[UsesClass(ReleaseVersionPolicy::class)]
#[UsesClass(ReleasedSections::class)]
#[UsesClass(ReleasedSectionDto::class)]
#[UsesClass(WatchedPaths::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(GitBranches::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[Small]
final class ChangelogCheckTest extends TestCase
{
    private const string WITH_ENTRY = "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- Old fix.\n\n- New fix.\n";

    private const string BASE_CHANGELOG = "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- Old fix.\n";

    private const string COMPOSER = '{"require": {"php": "^8.5"}}';

    private const string FEATURE_BRANCH = "feature/x\n";

    private const string ORIGIN_HEAD = "refs/remotes/origin/php8.5\n";

    private const string DEFAULT_BRANCH = "php8.5\n";

    private const string RESOLVED = "abc\n";

    private const string BASE = "base1\n";

    private const string RECORDED_ONE = '1 watched file changed; recorded by 1 new "## Unreleased" entry.';

    private const string CHANGED_SOURCE = "src/A.php\0";

    private const string COMPOSER_WITH_PCNTL = '{"require": {"php": "^8.5", "ext-pcntl": "*"}}';

    private const string CHANGED_COMPOSER = "composer.json\0";

    private const string COMPOSER_JSON = 'composer.json';

    private const string NOT_SHALLOW = "false\n";

    private const string EMPTY_BASE_CHANGELOG = "## Unreleased\n";

    private TempDir $project;

    private FakeProcessRunner $processes;

    protected function setUp(): void
    {
        $this->project   = TempDir::create('phpqa-changelog-check');
        $this->processes = new FakeProcessRunner();
        $this->project->write(self::COMPOSER_JSON, self::COMPOSER);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aMissingChangelogFailsWithoutTouchingGit(): void
    {
        $result = $this->check();

        self::assertFalse($result->passed());
        self::assertSame(['no CHANGELOG.md in the project root'], $result->problems);
        self::assertSame([], $this->processes->specs);
    }

    #[Test]
    public function anInvalidChangelogReportsEveryProblemWithoutTouchingGit(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n### Fixed\n\n### Fixed\n");

        $result = $this->check();

        self::assertSame(['line 3: "### Fixed" has no entries', 'line 5: "### Fixed" repeats the heading at line 3'], $result->problems);
        self::assertSame(['CHANGELOG.md "## Unreleased" is invalid.'], $result->report);
        self::assertSame([], $this->processes->specs);
    }

    #[Test]
    public function aShallowCloneFailsWithTheFetchRemedy(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::WITH_ENTRY);
        $this->processes->willSucceed("true\n");

        $result = $this->check();

        self::assertCount(1, $result->problems);
        self::assertStringContainsString('this is a shallow clone', $result->problems[0]);
        self::assertStringContainsString('`fetch-depth: 0` on actions/checkout', $result->problems[0]);
    }

    #[Test]
    public function aWatchedChangeWithANewEntryPasses(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::WITH_ENTRY);
        $this->featureBranch("src/A.php\0CHANGELOG.md\0");
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG);
        $this->composerUnchanged();

        $result = $this->check();

        self::assertSame([], $result->problems, implode("\n", $result->problems));
        self::assertTrue($result->passed());
        self::assertSame([
            'CHANGELOG.md "## Unreleased" is valid: 2 entries, the next release bumps the patch.',
            'Range: since the merge base with origin/php8.5 (base1).',
            self::RECORDED_ONE,
        ], $result->report);
    }

    #[Test]
    public function aChangelogAbsentAtTheBaseMakesEveryEntryNew(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::WITH_ENTRY);
        $this->featureBranch("src/A.php\0src/B.php\0");
        $this->processes->willFail(128);
        $this->composerUnchanged();

        $result = $this->check();

        self::assertTrue($result->passed());
        self::assertSame('2 watched files changed; recorded by 2 new "## Unreleased" entries.', $result->report[2]);
    }

    #[Test]
    public function anUnparseableBaseChangelogCountsEntriesItsTextDoesNotContain(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::WITH_ENTRY);
        $this->featureBranch(self::CHANGED_SOURCE);
        $this->processes->willSucceed()->willSucceed("# Changelog\n\n- Old fix.\n");
        $this->composerUnchanged();

        self::assertSame(self::RECORDED_ONE, $this->check()->report[2]);
    }

    #[Test]
    public function aWatchedChangeWithoutAnEntryOrTrailerFailsNamingTheFilesAndBothRemedies(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $this->featureBranch("src/A.php\0tests/ATest.php\0");
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG)->willSucceed("none\n");
        $this->composerUnchanged();

        $result = $this->check();

        self::assertCount(1, $result->problems);
        $problem = $result->problems[0];
        self::assertStringContainsString('1 watched file changed since the merge base with origin/php8.5 (base1) with no new "## Unreleased" entry and no "Changelog: none — <reason>" trailer:', $problem);
        self::assertStringContainsString('    src/A.php', $problem);
        self::assertStringNotContainsString('tests/ATest.php', $problem);
        self::assertStringContainsString('vendor/bin/changelog-release add-entry <heading> "<text>"', $problem);
        self::assertStringContainsString('Changelog: none — <why no consuming project could notice>', $problem);
        self::assertStringContainsString('Ignored for want of a reason: "none"', $problem);
    }

    #[Test]
    public function aLongListOfChangedFilesIsCut(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $files = '';
        for ($index = 1; $index <= 25; ++$index) {
            $files .= 'src/F' . $index . ".php\0";
        }

        $this->featureBranch($files);
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG)->willSucceed();
        $this->composerUnchanged();

        $problem = $this->check()->problems[0];

        self::assertStringContainsString('    src/F20.php', $problem);
        self::assertStringNotContainsString('src/F21.php', $problem);
        self::assertStringContainsString('    … and 5 more', $problem);
        self::assertStringNotContainsString('Ignored for want of a reason', $problem);
    }

    #[Test]
    public function exactlyTheListedNumberOfFilesIsShownWhole(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $files = '';
        for ($index = 1; $index <= 20; ++$index) {
            $files .= 'src/F' . $index . ".php\0";
        }

        $this->featureBranch($files);
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG)->willSucceed();
        $this->composerUnchanged();

        $problem = $this->check()->problems[0];

        self::assertStringContainsString("\n    src/F20.php\n", $problem);
        self::assertStringNotContainsString('more', $problem);
    }

    #[Test]
    public function theRemedyNamesTheCommandFirstAndTheTrailerSecond(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $this->featureBranch(self::CHANGED_SOURCE);
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG)->willSucceed();
        $this->composerUnchanged();

        self::assertStringEndsWith(
            "\n    src/A.php"
            . "\n  Record the change under the right heading of \"## Unreleased\" in CHANGELOG.md (vendor/bin/changelog-release add-entry <heading> \"<text>\"),"
            . "\n  or, when no consuming project could notice it, give one commit in the range the trailer"
            . "\n    Changelog: none — <why no consuming project could notice>",
            $this->check()->problems[0],
        );
    }

    #[Test]
    public function everyNewEntryCountsWhereverItSitsAmongTheOldOnes(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- Old fix.\n\n- New fix.\n\n- Another new fix.\n");
        $this->featureBranch(self::CHANGED_SOURCE);
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG);
        $this->composerUnchanged();

        self::assertSame('1 watched file changed; recorded by 2 new "## Unreleased" entries.', $this->check()->report[2]);
    }

    #[Test]
    public function anUnparseableBaseChangelogCountsOnlyTheEntriesItsTextLacks(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- Old fix.\n\n- New fix.\n\n- Another new fix.\n");
        $this->featureBranch(self::CHANGED_SOURCE);
        $this->processes->willSucceed()->willSucceed("# Changelog\n\n- Old fix.\n");
        $this->composerUnchanged();

        self::assertSame('1 watched file changed; recorded by 2 new "## Unreleased" entries.', $this->check()->report[2]);
    }

    #[Test]
    public function anEmptySectionIsValidAndDuesNoRelease(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "# Changelog\n\n## Unreleased\n");
        $this->featureBranch("tests/ATest.php\0");
        $this->composerUnchanged();

        $result = $this->check();

        self::assertTrue($result->passed());
        self::assertSame('CHANGELOG.md "## Unreleased" is valid: no entries, no release is due.', $result->report[0]);
    }

    #[Test]
    public function aValidTrailerStandsInForAnEntry(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $this->featureBranch(self::CHANGED_SOURCE);
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG)->willSucceed("none — internal refactor only\n");
        $this->composerUnchanged();

        $result = $this->check();

        self::assertTrue($result->passed());
        self::assertSame('1 watched file changed; a "Changelog: none — <reason>" trailer states no entry is needed.', $result->report[2]);
    }

    #[Test]
    public function nothingWatchedChangingNeedsNoEntry(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $this->featureBranch("tests/ATest.php\0docs/a.md\0");
        $this->composerUnchanged();

        $result = $this->check();

        self::assertTrue($result->passed());
        self::assertSame('2 files changed, none of them watched (src/, composer.json): no entry needed.', $result->report[2]);
    }

    #[Test]
    public function aChangedRequirementWithoutABreakingEntryFails(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::WITH_ENTRY);
        $this->project->write(self::COMPOSER_JSON, self::COMPOSER_WITH_PCNTL);
        $this->featureBranch(self::CHANGED_COMPOSER);
        $this->processes->willSucceed()->willSucceed(self::BASE_CHANGELOG);
        $this->processes->willSucceed()->willSucceed(self::COMPOSER);

        $result = $this->check();

        self::assertSame(
            ['composer.json "require" changed since the merge base with origin/php8.5 (base1) (ext-pcntl * added), and "## Unreleased" has no "### Changed — breaking" entry: a new or tightened requirement breaks every consumer that cannot meet it, so record it there.'],
            $result->problems,
        );
    }

    #[Test]
    public function aChangedRequirementWithABreakingEntryPasses(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n### Changed — breaking\n\n- Requires ext-pcntl.\n");
        $this->project->write(self::COMPOSER_JSON, self::COMPOSER_WITH_PCNTL);
        $this->featureBranch(self::CHANGED_COMPOSER);
        $this->processes->willFail(128);
        $this->processes->willSucceed()->willSucceed(self::COMPOSER);

        self::assertTrue($this->check()->passed());
    }

    #[Test]
    public function underThePhpLinePolicyAMissingComposerJsonCanOnlyBeMeasuredOnABranch(): void
    {
        \Safe\unlink($this->project->path . '/composer.json');
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $this->processes->willSucceed(self::NOT_SHALLOW)->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::DEFAULT_BRANCH);

        $result = $this->check(policy: ReleaseVersionPolicy::lockedMajorFromPhpRequirement());

        self::assertSame(['composer.json has no require.php, so it names no release line'], $result->problems);
    }

    #[Test]
    public function aHistoryProblemFailsTheCheck(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);
        $this->processes->willSucceed(self::NOT_SHALLOW)->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::DEFAULT_BRANCH)->willSucceed("v84.0.0\n");

        $result = $this->check();

        self::assertFalse($result->passed());
        self::assertStringContainsString('no X.Y.Z release tag', $result->problems[0]);
    }

    #[Test]
    public function aSectionReleasedSinceTheBaseRecordsTheChangeBeforeItIsTagged(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "# Changelog\n\n## Unreleased\n\n## 85.1.0 — 2026-10-09\n\n### Fixed\n\n- A fix.\n\n## 85.0.0 — 2026-10-02\n\n### Added\n\n- First.\n");
        $this->featureBranch(self::CHANGED_SOURCE);
        $this->processes->willSucceed()->willSucceed("# Changelog\n\n## Unreleased\n\n## 85.0.0 — 2026-10-02\n\n### Added\n\n- First.\n");
        $this->composerUnchanged();

        $result = $this->check();

        self::assertSame([], $result->problems);
        self::assertSame(self::RECORDED_ONE, $result->report[2]);
    }

    #[Test]
    public function aBreakingEntryInASectionReleasedSinceTheBaseCoversARequirementChange(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n## 85.1.0 — 2026-10-09\n\n### Changed — breaking\n\n- Requires ext-pcntl.\n");
        $this->project->write(self::COMPOSER_JSON, self::COMPOSER_WITH_PCNTL);
        $this->featureBranch(self::CHANGED_COMPOSER);
        $this->processes->willSucceed()->willSucceed(self::EMPTY_BASE_CHANGELOG);
        $this->processes->willSucceed()->willSucceed(self::COMPOSER);

        $result = $this->check();

        self::assertSame([], $result->problems);
        self::assertCount(11, $this->processes->specs, 'the base CHANGELOG.md is read once, for both checks');
    }

    #[Test]
    public function aBreakingEntryInAReleasedSectionCountsBesideOtherUnreleasedHeadings(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n### Fixed\n\n- A fix.\n\n## 85.1.0 — 2026-10-09\n\n### Added\n\n- A feature.\n\n### Changed — breaking\n\n- Requires ext-pcntl.\n");
        $this->project->write(self::COMPOSER_JSON, self::COMPOSER_WITH_PCNTL);
        $this->featureBranch(self::CHANGED_COMPOSER);
        $this->processes->willSucceed()->willSucceed(self::EMPTY_BASE_CHANGELOG);
        $this->processes->willSucceed()->willSucceed(self::COMPOSER);

        self::assertSame([], $this->check()->problems);
    }

    #[Test]
    public function anUnreleasedBreakingEntryNeedsNoReadOfTheBaseChangelog(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n### Changed — breaking\n\n- Requires ext-pcntl.\n");
        $this->project->write(self::COMPOSER_JSON, self::COMPOSER_WITH_PCNTL);
        $this->featureBranch(self::CHANGED_COMPOSER);
        $this->processes->willSucceed()->willSucceed(self::COMPOSER);

        $result = $this->check(new WatchedPaths('src/'));

        self::assertSame([], $result->problems);
        self::assertNotContains('git cat-file -e base1:CHANGELOG.md', $this->processes->commandLines());
    }

    #[Test]
    public function anInvalidReleasedSectionFailsTheCheck(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n## 85.1.0 — 2026-10-09\n\nLoose text.\n");
        $this->featureBranch(self::CHANGED_SOURCE);
        $this->processes->willSucceed()->willSucceed(self::EMPTY_BASE_CHANGELOG);

        $result = $this->check();

        self::assertSame(['CHANGELOG.md is invalid:' . "\n" . '  - line 5: text outside a "###" heading: "Loose text."'], $result->problems);
    }

    #[Test]
    public function aBreakingEntryIsReportedAsABreakingRelease(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n### Fixed\n\n- A fix.\n\n### Removed\n\n- Gone.\n");
        $this->processes->willSucceed("true\n");

        self::assertSame('CHANGELOG.md "## Unreleased" is valid: 2 entries, the next release is breaking.', $this->check()->report[0]);
    }

    #[Test]
    public function theReleasePolicyDecidesWhichTagTheDefaultBranchIsMeasuredFrom(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BASE_CHANGELOG);

        $this->onTheDefaultBranchWithTags("85.1.0\n86.0.0\n");
        self::assertSame('Range: since the last release tag 85.1.0.', $this->check(policy: ReleaseVersionPolicy::lockedMajorFromPhpRequirement())->report[1]);

        $this->onTheDefaultBranchWithTags("85.1.0\n86.0.0\n");
        self::assertSame('Range: since the last release tag 86.0.0.', $this->check()->report[1]);
    }

    private function onTheDefaultBranchWithTags(string $tags): void
    {
        $this->processes
            ->willSucceed(self::NOT_SHALLOW)
            ->willSucceed(self::ORIGIN_HEAD)
            ->willSucceed(self::DEFAULT_BRANCH)
            ->willSucceed($tags)
            ->willSucceed()
            ->willSucceed()
        ;
        $this->composerUnchanged();
    }

    private function featureBranch(string $changedFiles): void
    {
        $this->processes
            ->willSucceed(self::NOT_SHALLOW)
            ->willSucceed(self::ORIGIN_HEAD)
            ->willSucceed(self::FEATURE_BRANCH)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::BASE)
            ->willSucceed($changedFiles)
            ->willSucceed()
        ;
    }

    private function composerUnchanged(): void
    {
        $this->processes->willSucceed()->willSucceed(self::COMPOSER);
    }

    private function check(?WatchedPaths $watched = null, ReleaseVersionPolicy $policy = new ReleaseVersionPolicy()): ChangelogCheckResultDto
    {
        $root = $this->project->path;

        return new ChangelogCheck()->check(
            $root,
            new ChangelogGit($this->processes, $root),
            new GitBranches($this->processes, $root),
            new EnvironmentReader([]),
            $watched ?? new WatchedPaths('src/', self::COMPOSER_JSON),
            $policy,
        );
    }
}
