<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\InfectionDiffModeEnum;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffBaseDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffBaseResolver;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The decision table of automatic diff mode, against a fake git: which base a
 * run diffs against, or why it falls back to the full run.
 *
 * @internal
 */
#[CoversClass(InfectionDiffBaseResolver::class)]
#[CoversClass(InfectionDiffBaseDto::class)]
#[UsesClass(InfectionOptionsDto::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(GitBranches::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[Small]
final class InfectionDiffBaseResolverTest extends TestCase
{
    private const string ROOT = '/p';

    private const string CURRENT = 'git rev-parse --abbrev-ref HEAD';

    private const string ORIGIN_HEAD = 'git symbolic-ref refs/remotes/origin/HEAD';

    private const string DEFAULT_BRANCH = "refs/remotes/origin/php8.5\n";

    private const string FEATURE_BRANCH = "feature/x\n";

    private const string MERGE_BASE = "abc1234def\n";

    private const string FULL_RUN = 'Infection: full run';

    private const string RESOLVED = "abc\n";

    private const string MERGE_BASE_SHA = 'abc1234def';

    private FakeProcessRunner $processes;

    protected function setUp(): void
    {
        $this->processes = new FakeProcessRunner();
    }

    #[Test]
    public function anExplicitRefIsUsedAsGivenAndStrictWithoutAskingGit(): void
    {
        $base = $this->resolve($this->options('origin/main'));

        self::assertSame('origin/main', $base->ref);
        self::assertTrue($base->strict, 'an explicit base keeps the clean-tree refusal');
        self::assertFalse($base->isFullRun());
        self::assertStringContainsString("diff mode against the configured base 'origin/main'", $base->description);
        self::assertSame([], $this->processes->specs);
    }

    #[Test]
    public function aForcedFullRunAsksGitNothingAndSaysWhy(): void
    {
        $base = $this->resolve($this->options(null, InfectionDiffModeEnum::Full));

        self::assertTrue($base->isFullRun());
        self::assertNull($base->ref);
        self::assertStringContainsString(self::FULL_RUN, $base->description);
        self::assertStringContainsString('withInfectionFullRun() / infectionDiffBase=full', $base->description);
        self::assertSame([], $this->processes->specs);
    }

    #[Test]
    public function aFeatureBranchDiffsAgainstItsMergeBaseWithTheRemoteDefaultBranch(): void
    {
        $this->processes->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::DEFAULT_BRANCH)->willSucceed(self::RESOLVED)->willSucceed(self::MERGE_BASE);

        $base = $this->resolve();

        self::assertSame(self::MERGE_BASE_SHA, $base->ref, 'the merge base itself, so the diff is exactly what the branch added');
        self::assertFalse($base->strict, 'auto mode never fails a run over local edits');
        self::assertFalse($base->isFullRun());
        self::assertSame(
            "Infection: auto diff mode — branch 'feature/x' against origin/php8.5 (merge base abc1234def), committed history only.",
            $base->description,
        );
        self::assertSame([
            self::CURRENT,
            self::ORIGIN_HEAD,
            "git rev-parse --verify --quiet 'origin/php8.5^{commit}'",
            'git merge-base HEAD origin/php8.5',
        ], $this->processes->commandLines());
        self::assertSame(self::ROOT, $this->processes->specs[0]->cwd);
        self::assertFalse($this->processes->specs[0]->streamOutput);
    }

    #[Test]
    public function withoutTheRemoteBranchTheLocalDefaultBranchIsTheBase(): void
    {
        $this->processes->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::DEFAULT_BRANCH)->willFail(1)->willSucceed(self::RESOLVED)->willSucceed(self::MERGE_BASE);

        $base = $this->resolve();

        self::assertSame(self::MERGE_BASE_SHA, $base->ref);
        self::assertStringContainsString("against php8.5 (merge base abc1234def)", $base->description);
        self::assertSame('git merge-base HEAD php8.5', $this->processes->lastSpec()->commandLine());
    }

    #[Test]
    public function onTheDefaultBranchItIsAFullRunThatSaysSo(): void
    {
        $this->processes->willSucceed("php8.5\n")->willSucceed(self::DEFAULT_BRANCH);

        $base = $this->resolve();

        self::assertTrue($base->isFullRun());
        self::assertSame("Infection: full run — auto diff mode does not apply: on the default branch 'php8.5'.", $base->description);
        self::assertCount(2, $this->processes->specs);
    }

    #[Test]
    public function aDefaultBranchKnownOnlyToTheRemoteIsStillRecognised(): void
    {
        $this->processes->willSucceed("main\n")->willFail(128)->willSucceed("ref: refs/heads/main\tHEAD\nabc\tHEAD\n");

        $base = $this->resolve();

        self::assertTrue($base->isFullRun());
        self::assertStringContainsString("on the default branch 'main'", $base->description);
        self::assertSame('git ls-remote --symref origin HEAD', $this->processes->lastSpec()->commandLine());
    }

    #[Test]
    public function anUnknownDefaultBranchFallsBackToAFullRunNamingTheRemedy(): void
    {
        $this->processes->willSucceed(self::FEATURE_BRANCH)->willFail(128)->willFail(128);

        $base = $this->resolve();

        self::assertTrue($base->isFullRun());
        self::assertStringContainsString(self::FULL_RUN, $base->description);
        self::assertStringContainsString('the default branch cannot be told', $base->description);
        self::assertStringContainsString('git remote set-head origin --auto', $base->description);
    }

    #[Test]
    public function aDetachedHeadOutsideAPullRequestFallsBackToAFullRun(): void
    {
        $this->processes->willSucceed("HEAD\n");

        $base = $this->resolve();

        self::assertTrue($base->isFullRun());
        self::assertStringContainsString('HEAD is detached and this is not a pull request build', $base->description);
        self::assertCount(1, $this->processes->specs, 'no default-branch probe is needed to know there is no branch');
    }

    #[Test]
    public function aPullRequestBuildDiffsAgainstItsTargetBranchWithoutAskingForTheBranches(): void
    {
        $this->processes->willSucceed(self::RESOLVED)->willSucceed(self::MERGE_BASE);

        $base = $this->resolve(env: ['GITHUB_BASE_REF' => 'php8.4']);

        self::assertSame(self::MERGE_BASE_SHA, $base->ref);
        self::assertSame(
            "Infection: auto diff mode — pull request into 'php8.4' against origin/php8.4 (merge base abc1234def), committed history only.",
            $base->description,
        );
        self::assertSame("git rev-parse --verify --quiet 'origin/php8.4^{commit}'", $this->processes->commandLines()[0]);
    }

    #[Test]
    public function aBaseBranchMissingFromTheCloneFallsBackToAFullRun(): void
    {
        $this->processes->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::DEFAULT_BRANCH)->willFail(1)->willFail(1);

        $base = $this->resolve();

        self::assertTrue($base->isFullRun());
        self::assertStringContainsString('neither origin/php8.5 nor php8.5 is in this clone', $base->description);
        self::assertStringContainsString('git fetch origin php8.5', $base->description);
    }

    #[Test]
    public function noMergeBaseAsInAShallowCloneFallsBackToAFullRunThatSaysSo(): void
    {
        $this->processes->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::DEFAULT_BRANCH)->willSucceed(self::RESOLVED)->willFail(1);

        $base = $this->resolve();

        self::assertTrue($base->isFullRun());
        self::assertStringContainsString('HEAD and origin/php8.5 share no merge base in this clone', $base->description);
        self::assertStringContainsString('git fetch --unshallow', $base->description);
        self::assertStringContainsString('fetch-depth: 0', $base->description);
    }

    #[Test]
    public function anEmptyMergeBaseAnswerIsNoMergeBase(): void
    {
        $this->processes->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::DEFAULT_BRANCH)->willSucceed(self::RESOLVED)->willSucceed("\n");

        self::assertTrue($this->resolve()->isFullRun());
    }

    private function options(?string $diffBase = null, ?InfectionDiffModeEnum $mode = null): InfectionOptionsDto
    {
        return new InfectionOptionsDto(
            enabled: true,
            threads: 1,
            onlyCovered: false,
            minMsi: 60,
            minCoveredMsi: 80,
            diffBase: $diffBase,
            diffCoveredMsi: 80,
            diffMode: $mode,
        );
    }

    /** @param array<string, string> $env */
    private function resolve(?InfectionOptionsDto $options = null, array $env = []): InfectionDiffBaseDto
    {
        return new InfectionDiffBaseResolver()->resolve($options ?? $this->options(), $this->processes, self::ROOT, new EnvironmentReader($env));
    }
}
