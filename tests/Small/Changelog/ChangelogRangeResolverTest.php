<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\ChangelogGit;
use LTS\PHPQA\Changelog\ChangelogRangeResolver;
use LTS\PHPQA\Changelog\Dto\ChangelogRangeDto;
use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\ReleaseVersionCalculator;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
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
#[CoversClass(ChangelogRangeResolver::class)]
#[CoversClass(ChangelogRangeDto::class)]
#[UsesClass(ChangelogGit::class)]
#[UsesClass(ChangelogHistoryException::class)]
#[UsesClass(ChangelogReleaseException::class)]
#[UsesClass(ReleaseVersionCalculator::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(GitBranches::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[Small]
final class ChangelogRangeResolverTest extends TestCase
{
    private const string COMPOSER = '{"require": {"php": "^8.5"}}';

    private const string ORIGIN_HEAD = "refs/remotes/origin/php8.5\n";

    private const string BASE = "abc123\n";

    private const string FETCH_HINT = '`fetch-depth: 0` on actions/checkout';

    private const string RESOLVED = "abc\n";

    private const string FEATURE_BRANCH = "feature/x\n";

    private const string DEFAULT_BRANCH = "php8.5\n";

    private FakeProcessRunner $processes;

    protected function setUp(): void
    {
        $this->processes = new FakeProcessRunner();
    }

    #[Test]
    public function aFeatureBranchIsMeasuredFromItsMergeBaseWithTheRemoteDefaultBranch(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::RESOLVED)->willSucceed(self::BASE);

        $range = $this->resolve();

        self::assertSame('abc123', $range->base);
        self::assertSame('since the merge base with origin/php8.5 (abc123)', $range->description);
        self::assertSame([
            'git symbolic-ref refs/remotes/origin/HEAD',
            'git rev-parse --abbrev-ref HEAD',
            "git rev-parse --verify --quiet 'origin/php8.5^{commit}'",
            'git merge-base HEAD origin/php8.5',
        ], $this->processes->commandLines());
    }

    #[Test]
    public function withoutTheRemoteBranchTheLocalOneIsUsed(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::FEATURE_BRANCH)->willFail(1)->willSucceed(self::RESOLVED)->willSucceed(self::BASE);

        self::assertSame('since the merge base with php8.5 (abc123)', $this->resolve()->description);
        self::assertSame('git merge-base HEAD php8.5', $this->processes->lastSpec()->commandLine());
    }

    #[Test]
    public function aPullRequestBuildIsMeasuredFromItsTargetBranchWithoutAskingForTheDefault(): void
    {
        $this->processes->willSucceed(self::RESOLVED)->willSucceed(self::BASE);

        $range = $this->resolve(['GITHUB_BASE_REF' => 'php8.4']);

        self::assertSame('since the merge base with origin/php8.4 (abc123)', $range->description);
        self::assertSame("git rev-parse --verify --quiet 'origin/php8.4^{commit}'", $this->processes->commandLines()[0]);
    }

    #[Test]
    public function theDefaultBranchIsMeasuredFromTheLatestReleaseTagOnItsLine(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::DEFAULT_BRANCH)->willSucceed("84.0.0\n85.0.0\n85.1.0\n");

        $range = $this->resolve();

        self::assertSame('85.1.0', $range->base);
        self::assertSame('since the last release tag 85.1.0', $range->description);
        self::assertSame('git tag --list', $this->processes->lastSpec()->commandLine());
    }

    #[Test]
    public function aDetachedPushBuildOfTheDefaultBranchIsTheDefaultBranch(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed("HEAD\n")->willSucceed("85.0.0\n");

        self::assertSame('85.0.0', $this->resolve(['GITHUB_REF_NAME' => 'php8.5'])->base);
    }

    #[Test]
    public function aDetachedHeadOutsideAPullRequestFails(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed("HEAD\n");

        $this->expectException(ChangelogHistoryException::class);
        $this->expectExceptionMessageIsOrContains('HEAD is detached and this is not a pull request build');

        $this->resolve(['GITHUB_REF_NAME' => 'feature/x']);
    }

    #[Test]
    public function anUnknownDefaultBranchFails(): void
    {
        $this->processes->willFail(128)->willFail(128);

        $this->expectException(ChangelogHistoryException::class);
        $this->expectExceptionMessageIsOrContains('cannot tell the default branch');

        $this->resolve();
    }

    #[Test]
    public function aBranchThatIsNotInTheCloneFails(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::FEATURE_BRANCH)->willFail(1)->willFail(1);

        $this->expectException(ChangelogHistoryException::class);
        $this->expectExceptionMessageIsOrContains('neither origin/php8.5 nor php8.5 is in this clone');

        $this->resolve();
    }

    #[Test]
    public function noMergeBaseFailsWithTheFetchDepthRemedy(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::FEATURE_BRANCH)->willSucceed(self::RESOLVED)->willFail(1);

        $this->expectException(ChangelogHistoryException::class);
        $this->expectExceptionMessageIsOrContains(self::FETCH_HINT);

        $this->resolve();
    }

    #[Test]
    public function noTagOnTheLineFailsWithTheFetchAndFirstTagRemedies(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::DEFAULT_BRANCH)->willSucceed("84.0.0\n");

        try {
            $this->resolve();
            self::fail('expected the missing tag to fail');
        } catch (ChangelogHistoryException $changelogHistoryException) {
            self::assertStringContainsString('no 85.N.N release tag is in this clone', $changelogHistoryException->getMessage());
            self::assertStringContainsString(self::FETCH_HINT, $changelogHistoryException->getMessage());
            self::assertStringContainsString('tag the first release 85.0.0', $changelogHistoryException->getMessage());
        }
    }

    #[Test]
    public function theDefaultBranchNeedsAComposerJsonNamingTheLine(): void
    {
        $this->processes->willSucceed(self::ORIGIN_HEAD)->willSucceed(self::DEFAULT_BRANCH);

        $this->expectException(ChangelogReleaseException::class);

        $this->resolve([], '{"require": {"php": ">=8.5"}}');
    }

    /** @param array<string, string> $env */
    private function resolve(array $env = [], string $composerJson = self::COMPOSER): ChangelogRangeDto
    {
        return new ChangelogRangeResolver()->resolve(
            new ChangelogGit($this->processes, '/p'),
            new GitBranches($this->processes, '/p'),
            new EnvironmentReader($env),
            $composerJson,
        );
    }
}
