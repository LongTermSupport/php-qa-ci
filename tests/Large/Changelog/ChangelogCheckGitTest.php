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

    private GitSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create([
            'composer.json'      => self::COMPOSER,
            self::CHANGELOG_FILE => self::CHANGELOG,
            self::SOURCE         => self::PHP_FILE,
            'tests/ATest.php'    => self::PHP_FILE,
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
    public function aTestOnlyChangeNeedsNoEntry(): void
    {
        $this->sandbox->commit('tests/ATest.php', "<?php\n// more\n");

        $result = $this->check();

        self::assertTrue($result->passed());
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
