<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Changelog;

use LogicException;
use LTS\PHPQA\Changelog\ChangelogCheck;
use LTS\PHPQA\Changelog\ChangelogGit;
use LTS\PHPQA\Changelog\Dto\ChangelogCheckResultDto;
use LTS\PHPQA\Changelog\ReleaseVersionPolicyLoader;
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
use Symfony\Component\Process\Process;

/**
 * The release a consuming project gets from the shipped workflow, end to end
 * against real git, under each versioning policy: what `next-version` names,
 * what `pending-tags` tags once the release pull request is merged, and the
 * range the changelog lane measures on the default branch afterwards. The
 * policy comes from the project's qaConfig/qa.php, as the CLI reads it.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ReleasePolicyFlowTest extends TestCase
{
    private const string BIN = __DIR__ . '/../../../bin/changelog-release';

    private const string CHANGELOG_FILE = 'CHANGELOG.md';

    private const string QA_PHP = 'qaConfig/qa.php';

    private const string SOURCE = 'src/A.php';

    private const string NEXT_VERSION = 'next-version';

    private const string PENDING_TAGS = 'pending-tags';

    private const string CHANGED_SOURCE = "<?php\n// changed\n";

    private const string TAG = 'tag';

    private const string BREAKING = "# Changelog\n\n## Unreleased\n\n### Removed\n\n- The old flag.\n\n### Fixed\n\n- A fix.\n";

    private const string ADDED = "# Changelog\n\n## Unreleased\n\n### Added\n\n- A feature.\n";

    private const string FIXED = "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A fix.\n";

    private ?GitSandbox $sandbox = null;

    protected function tearDown(): void
    {
        $this->sandbox?->remove();
    }

    #[Test]
    public function aProjectWithNoSettingReleasesABreakingChangeAsTheNextMajor(): void
    {
        $sandbox = $this->project('1.4.2');
        $sandbox->commit(self::SOURCE, self::CHANGED_SOURCE);
        $sandbox->commit(self::CHANGELOG_FILE, self::BREAKING);

        self::assertSame("2.0.0\n", $this->cli(self::NEXT_VERSION)->getOutput());

        $commit = $this->releaseThroughAPullRequest('2.0.0');

        $merged = $this->check();
        self::assertSame([], $merged->problems, implode("\n", $merged->problems));
        self::assertSame('Range: since the last release tag 1.4.2.', $merged->report[1]);
        self::assertSame('2.0.0 ' . $commit . "\n", $this->cli(self::PENDING_TAGS)->getOutput());

        $sandbox->git(self::TAG, '2.0.0', $commit);
        self::assertSame('', $this->cli(self::PENDING_TAGS)->getOutput());
        self::assertSame('Range: since the last release tag 2.0.0.', $this->check()->report[1]);
    }

    #[Test]
    public function aProjectWithNoSettingReleasesAnAdditionAsTheNextMinor(): void
    {
        $this->project('1.4.2')->commit(self::CHANGELOG_FILE, self::ADDED);

        self::assertSame("1.5.0\n", $this->cli(self::NEXT_VERSION)->getOutput());
    }

    #[Test]
    public function aProjectNeverReleasedStartsAtZeroDotOne(): void
    {
        $this->project(null)->commit(self::CHANGELOG_FILE, self::BREAKING);

        self::assertSame("0.1.0\n", $this->cli(self::NEXT_VERSION)->getOutput());
    }

    #[Test]
    public function aTagPrefixIsCarriedOntoTheTagAndNotIntoTheVersion(): void
    {
        $sandbox = $this->project('v1.4.2', "semanticVersioning('v')");
        $sandbox->git(self::TAG, '7.0.0');
        $sandbox->commit(self::SOURCE, self::CHANGED_SOURCE);
        $sandbox->commit(self::CHANGELOG_FILE, self::FIXED);

        self::assertSame("1.4.3\n", $this->cli(self::NEXT_VERSION)->getOutput());

        $commit = $this->releaseThroughAPullRequest('1.4.3');

        self::assertSame('Range: since the last release tag v1.4.2.', $this->check()->report[1]);
        self::assertSame('v1.4.3 ' . $commit . "\n", $this->cli(self::PENDING_TAGS)->getOutput());

        $notes = $this->cli('notes', 'v1.4.3');
        self::assertSame(0, $notes->getExitCode(), $notes->getErrorOutput());
        self::assertStringStartsWith('1.4.3 — 2026-10-09', $notes->getOutput());
    }

    #[Test]
    public function aLockedMajorReleasesABreakingChangeAsTheNextMinor(): void
    {
        $sandbox = $this->project('85.2.0', 'lockedMajorFromPhpRequirement()');
        $sandbox->git(self::TAG, '86.0.0');
        $sandbox->commit(self::SOURCE, self::CHANGED_SOURCE);
        $sandbox->commit(self::CHANGELOG_FILE, self::BREAKING);

        self::assertSame("85.3.0\n", $this->cli(self::NEXT_VERSION)->getOutput());

        $commit = $this->releaseThroughAPullRequest('85.3.0');

        self::assertSame('Range: since the last release tag 85.2.0.', $this->check()->report[1]);
        self::assertSame('85.3.0 ' . $commit . "\n", $this->cli(self::PENDING_TAGS)->getOutput());
    }

    /** A project on php8.5 tagged $tag (none when null), releasing by the policy $policy names, or the default. */
    private function project(?string $tag, ?string $policy = null): GitSandbox
    {
        $files = [
            'composer.json'      => "{\n  \"require\": {\n    \"php\": \"^8.5\"\n  }\n}\n",
            self::CHANGELOG_FILE => "# Changelog\n\n## Unreleased\n",
            self::SOURCE         => "<?php\n",
        ];
        if (null !== $policy) {
            $files[self::QA_PHP] = \sprintf(
                '<?php
return static fn (\LTS\PHPQA\Pipeline\Config\QaConfigBuilder $qa) => $qa->withReleaseVersionPolicy(' . \LTS\PHPQA\Changelog\ReleaseVersionPolicy::class . '::%s);
',
                $policy,
            );
        }

        $this->sandbox = GitSandbox::create($files);
        if (null !== $tag) {
            $this->sandbox->git(self::TAG, $tag);
        }

        return $this->sandbox;
    }

    /** What release.yml does: apply on the release branch, merge it with a merge commit; the release commit's sha. */
    private function releaseThroughAPullRequest(string $version): string
    {
        $sandbox = $this->sandbox();
        $sandbox->git('checkout', '-b', 'chore/release-' . GitSandbox::DEFAULT_BRANCH);

        $apply = $this->cli('apply', $version, '2026-10-09');
        self::assertSame(0, $apply->getExitCode(), $apply->getErrorOutput());
        $sandbox->git('commit', '-am', 'Release ' . $version);
        $commit = trim($sandbox->git('rev-parse', 'HEAD'));

        $sandbox->git('checkout', GitSandbox::DEFAULT_BRANCH);
        $sandbox->git('merge', '--no-ff', '-m', 'Merge the release', 'chore/release-' . GitSandbox::DEFAULT_BRANCH);

        return $commit;
    }

    /** The changelog lane on the default branch, under the policy the project declares. */
    private function check(): ChangelogCheckResultDto
    {
        $root      = $this->sandbox()->work;
        $processes = new SymfonyProcessRunner(new NullOutput());

        return new ChangelogCheck()->check(
            $root,
            new ChangelogGit($processes, $root),
            new GitBranches($processes, $root),
            new EnvironmentReader([]),
            new WatchedPaths('src/'),
            new ReleaseVersionPolicyLoader()->load($root),
        );
    }

    private function cli(string ...$arguments): Process
    {
        $process = new Process(['php', '-d', 'xdebug.mode=off', \Safe\realpath(self::BIN), ...array_values($arguments)], $this->sandbox()->work, GitSandbox::environment());
        $process->run();

        return $process;
    }

    private function sandbox(): GitSandbox
    {
        return $this->sandbox ?? throw new LogicException('no project yet');
    }
}
