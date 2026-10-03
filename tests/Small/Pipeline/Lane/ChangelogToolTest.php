<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

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
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\Changelog\ReleaseLine;
use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use LTS\PHPQA\Changelog\WatchedPaths;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Lane\ChangelogTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ChangelogTool::class)]
#[UsesClass(ChangelogCheck::class)]
#[UsesClass(ChangelogGit::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[UsesClass(ChangelogParser::class)]
#[UsesClass(ChangelogRangeResolver::class)]
#[UsesClass(ChangelogTrailers::class)]
#[UsesClass(ComposerRequirementChanges::class)]
#[UsesClass(ChangelogCheckResultDto::class)]
#[UsesClass(ChangelogDocumentDto::class)]
#[UsesClass(ChangelogHeadingBlockDto::class)]
#[UsesClass(ChangelogRangeDto::class)]
#[UsesClass(ChangelogTrailerVerdictDto::class)]
#[UsesClass(InvalidChangelogException::class)]
#[UsesClass(ReleaseLine::class)]
#[UsesClass(ReleaseVersionPolicy::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleasedSections::class)]
#[UsesClass(WatchedPaths::class)]
#[UsesClass(GitBranches::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[Small]
final class ChangelogToolTest extends TestCase
{
    private const string CHANGELOG = "# Changelog\n\n## Unreleased\n\n### Fixed\n\n- A fix.\n";

    private const string NOT_SHALLOW = "false\n";

    private const string RESOLVED = "abc\n";

    private const string MERGE_BASE = "base1\n";

    private const string EMPTY_COMPOSER = "{}\n";

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
    public function theLaneIsNamedAndIdentified(): void
    {
        self::assertSame('changelog', new ChangelogTool()->name());
        self::assertSame('phpqaci.changelog', new ChangelogTool()->identifier());
    }

    #[Test]
    public function offByDefaultTheLaneSkipsWithoutTouchingGit(): void
    {
        $result = new ChangelogTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame('off; withChangelogCheck(true) in qaConfig/qa.php enables it', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aCoveredRangePassesAndReportsWhatItEstablished(): void
    {
        $this->factory->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->factory->processes
            ->willSucceed(self::NOT_SHALLOW)
            ->willSucceed("refs/remotes/origin/main\n")
            ->willSucceed("feature/x\n")
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed("tests/ATest.php\0")
            ->willSucceed()
            ->willSucceed()
            ->willSucceed(self::EMPTY_COMPOSER)
        ;

        $result  = new ChangelogTool(new EnvironmentReader([]))->run($this->factory->context($this->enabled()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('[changelog] CHANGELOG.md "## Unreleased" is valid: 1 entry, the next release bumps the patch.', $printed);
        self::assertStringContainsString('[changelog] Range: since the merge base with origin/main (base1).', $printed);
        self::assertStringContainsString('[changelog] 1 file changed, none of them watched (src/): no entry needed.', $printed);
        self::assertSame($this->factory->project->path, $this->factory->processes->lastSpec()->cwd);
    }

    #[Test]
    public function aFailingCheckPrintsEveryProblemAndTheIdentifier(): void
    {
        $this->factory->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\nStray.\n\n### Bogus\n\n- X.\n");

        $result  = new ChangelogTool(new EnvironmentReader([]))->run($this->factory->context($this->enabled()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('2 changelog problems', $result->summary);
        self::assertStringContainsString('changelog: CHANGELOG.md DOES NOT RECORD THIS CHANGE CORRECTLY', $printed);
        self::assertStringContainsString('  - line 3: text outside a "###" heading: "Stray."', $printed);
        self::assertStringContainsString('  - line 5: "### Bogus" is not an allowed heading', $printed);
        self::assertStringContainsString('Allowed headings and what each releases as: docs/tools/changelog.md', $printed);
        self::assertStringContainsString('🪪  phpqaci.changelog  (vendor/bin/rule-doc phpqaci.changelog)', $printed);
    }

    #[Test]
    public function theFailureBannerSetsTheProblemsApartFromTheReport(): void
    {
        $this->factory->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\nStray.\n");

        new ChangelogTool(new EnvironmentReader([]))->run($this->factory->context($this->enabled()));

        $rule = str_repeat('=', 78);
        self::assertStringContainsString(
            "[changelog] CHANGELOG.md \"## Unreleased\" is invalid.\n"
            . "\n"
            . $rule . "\n"
            . "changelog: CHANGELOG.md DOES NOT RECORD THIS CHANGE CORRECTLY\n"
            . $rule . "\n"
            . "\n"
            . "  - line 3: text outside a \"###\" heading: \"Stray.\"\n"
            . "\n"
            . "Allowed headings and what each releases as: docs/tools/changelog.md\n",
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function theInjectedEnvironmentDecidesTheRange(): void
    {
        $this->factory->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->factory->processes
            ->willSucceed(self::NOT_SHALLOW)
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed("tests/ATest.php\0")
            ->willSucceed()
            ->willSucceed()
            ->willSucceed(self::EMPTY_COMPOSER)
        ;

        $result = new ChangelogTool(new EnvironmentReader(['GITHUB_BASE_REF' => 'release-train']))->run($this->factory->context($this->enabled()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString('[changelog] Range: since the merge base with origin/release-train (base1).', $this->factory->output->fetch());
    }

    #[Test]
    public function aMultiLineProblemKeepsItsLinesIndentedUnderItsBullet(): void
    {
        $this->factory->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->factory->processes
            ->willSucceed(self::NOT_SHALLOW)
            ->willSucceed("refs/remotes/origin/main\n")
            ->willSucceed("feature/x\n")
            ->willSucceed(self::RESOLVED)
            ->willSucceed(self::MERGE_BASE)
            ->willSucceed("src/A.php\0")
            ->willSucceed()
            ->willSucceed()
            ->willSucceed(self::CHANGELOG)
            ->willSucceed()
            ->willSucceed()
            ->willSucceed(self::EMPTY_COMPOSER)
        ;

        $result  = new ChangelogTool(new EnvironmentReader([]))->run($this->factory->context($this->enabled()));
        $printed = $this->factory->output->fetch();

        self::assertSame('1 changelog problem', $result->summary);
        self::assertStringContainsString('  - 1 watched file changed since the merge base with origin/main (base1)', $printed);
        self::assertStringContainsString("\n        src/A.php\n", $printed);
    }

    #[Test]
    public function theConfiguredReleasePolicyDecidesTheDefaultBranchRange(): void
    {
        $this->factory->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $locked = $this->factory->builder()->withChangelogCheck(true)->withChangelogWatchedPaths('src/')->withReleaseVersionPolicy(ReleaseVersionPolicy::lockedMajor(85))->build();

        $this->onTheDefaultBranch();
        new ChangelogTool(new EnvironmentReader([]))->run($this->factory->context($locked));
        self::assertStringContainsString('[changelog] Range: since the last release tag 85.1.0.', $this->factory->output->fetch());

        $this->onTheDefaultBranch();
        new ChangelogTool(new EnvironmentReader([]))->run($this->factory->context($this->enabled()));
        self::assertStringContainsString('[changelog] Range: since the last release tag 86.0.0.', $this->factory->output->fetch());
    }

    private function onTheDefaultBranch(): void
    {
        $this->factory->processes
            ->willSucceed(self::NOT_SHALLOW)
            ->willSucceed("refs/remotes/origin/main\n")
            ->willSucceed("main\n")
            ->willSucceed("85.1.0\n86.0.0\n")
            ->willSucceed()
            ->willSucceed()
            ->willSucceed(self::EMPTY_COMPOSER)
        ;
    }

    private function enabled(): \LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto
    {
        return $this->factory->builder()->withChangelogCheck(true)->withChangelogWatchedPaths('src/')->build();
    }
}
