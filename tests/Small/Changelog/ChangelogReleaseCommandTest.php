<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\BundledToolVersions;
use LTS\PHPQA\Changelog\ChangelogCheck;
use LTS\PHPQA\Changelog\ChangelogEntryAdder;
use LTS\PHPQA\Changelog\ChangelogGit;
use LTS\PHPQA\Changelog\ChangelogHeadingEnum;
use LTS\PHPQA\Changelog\ChangelogParser;
use LTS\PHPQA\Changelog\ChangelogReleaseCommand;
use LTS\PHPQA\Changelog\ChangelogReleaseWriter;
use LTS\PHPQA\Changelog\Dto\ChangelogDocumentDto;
use LTS\PHPQA\Changelog\Dto\ChangelogHeadingBlockDto;
use LTS\PHPQA\Changelog\Dto\ReleasedSectionDto;
use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\Changelog\ReleasedSections;
use LTS\PHPQA\Changelog\ReleaseLine;
use LTS\PHPQA\Changelog\ReleaseNotesRenderer;
use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use LTS\PHPQA\Changelog\ReleaseVersionPolicyLoader;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\RunningProcesses;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(ChangelogReleaseCommand::class)]
#[UsesClass(ChangelogCheck::class)]
#[UsesClass(ChangelogEntryAdder::class)]
#[UsesClass(ChangelogGit::class)]
#[UsesClass(ChangelogHeadingEnum::class)]
#[UsesClass(ChangelogParser::class)]
#[UsesClass(ChangelogReleaseWriter::class)]
#[UsesClass(ChangelogDocumentDto::class)]
#[UsesClass(ChangelogHeadingBlockDto::class)]
#[UsesClass(ReleasedSectionDto::class)]
#[UsesClass(ChangelogHistoryException::class)]
#[UsesClass(ChangelogReleaseException::class)]
#[UsesClass(InvalidChangelogException::class)]
#[UsesClass(ReleaseNotesRenderer::class)]
#[UsesClass(ReleaseLine::class)]
#[UsesClass(ReleaseVersionPolicy::class)]
#[UsesClass(ReleaseVersionPolicyLoader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ComposerBinDirReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\PlatformDetector::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ProjectConfigLoader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(ReleasedSections::class)]
#[UsesClass(BundledToolVersions::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(RunningProcesses::class)]
#[Small]
final class ChangelogReleaseCommandTest extends TestCase
{
    private const string COMPOSER = '{"require": {"php": "^8.5"}}';

    private const string CHANGELOG = "# Changelog\n\n## Unreleased\n\n### Added\n\n- A feature.\n\n## 85.0.0 — 2026-09-01\n\n### Fixed\n\n- Old.\n";

    private const string EMPTY = "# Changelog\n\n## Unreleased\n\n## 85.0.0 — 2026-09-01\n\n### Fixed\n\n- Old.\n";

    private const string NEXT_VERSION = 'next-version';

    private const string ADD_ENTRY = 'add-entry';

    private const string APPLY = 'apply';

    private const string NOTES = 'notes';

    private const string VERSION = '85.1.0';

    private const string V850 = '85.0.0';

    private const string PENDING_TAGS = 'pending-tags';

    private const string ADD_TOOL_UPDATES = 'add-tool-updates';

    private const string TAG_LIST = 'git tag --list';

    private const string PHPSTAN_PIN = '<phar name="phpstan" installed="2.2.16"/>';

    private const string EXTRA = 'extra';

    private const string BREAKING = "# Changelog\n\n## Unreleased\n\n### Changed — breaking\n\n- A new requirement.\n\n### Fixed\n\n- A fix.\n";

    private const string OLD_NOTES = "85.0.0 — 2026-09-01\n\nFixed\n-----\n\n- Old.\n";

    private TempDir $project;

    private FakeProcessRunner $processes;

    private BufferedOutput $stdout;

    private BufferedOutput $stderr;

    protected function setUp(): void
    {
        $this->project   = TempDir::create('phpqa-changelog-release');
        $this->processes = new FakeProcessRunner();
        $this->stdout    = new BufferedOutput();
        $this->stderr    = new BufferedOutput();
        $this->project->write('composer.json', self::COMPOSER);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function nextVersionPrintsTheBumpedVersionOnTheLine(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->processes->willSucceed("84.0.0\n85.0.0\n");

        self::assertSame(0, $this->invoke(self::NEXT_VERSION));
        self::assertSame("85.1.0\n", $this->stdout->fetch());
        self::assertSame([self::TAG_LIST], $this->processes->commandLines());
    }

    #[Test]
    public function underSemanticVersioningABreakingEntryReleasesTheNextMajor(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BREAKING);
        $this->processes->willSucceed("1.3.0\n1.4.2\nv9.0.0\n");

        self::assertSame(0, $this->invoke(self::NEXT_VERSION));
        self::assertSame("2.0.0\n", $this->stdout->fetch());
    }

    #[Test]
    public function underALockedMajorABreakingEntryReleasesTheNextMinor(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::BREAKING);
        $this->processes->willSucceed("85.0.0\n85.2.0\n86.0.0\n");

        self::assertSame(0, $this->invokeUnder(ReleaseVersionPolicy::lockedMajorFromPhpRequirement(), self::NEXT_VERSION));
        self::assertSame("85.3.0\n", $this->stdout->fetch());
    }

    #[Test]
    public function pendingTagsNamesTheTagWithThePolicysPrefix(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "# Changelog\n\n## Unreleased\n\n## 1.5.0 — 2026-10-09\n\n### Added\n\n- One.\n\n## 1.4.2 — 2026-09-01\n\n### Fixed\n\n- Old.\n");
        $this->processes->willSucceed("v1.4.2\n1.5.0\n")->willSucceed("aaa111\n");

        self::assertSame(0, $this->invokeUnder(ReleaseVersionPolicy::semanticVersioning('v'), self::PENDING_TAGS));
        self::assertSame("v1.5.0 aaa111\n", $this->stdout->fetch());
    }

    #[Test]
    public function notesAcceptsTheTagAsWellAsTheVersion(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $policy = ReleaseVersionPolicy::semanticVersioning('v');

        self::assertSame(0, $this->invokeUnder($policy, self::NOTES, 'v85.0.0'));
        self::assertSame(self::OLD_NOTES, $this->stdout->fetch());
        self::assertSame(0, $this->invokeUnder($policy, self::NOTES, self::V850));
        self::assertSame(self::OLD_NOTES, $this->stdout->fetch());
    }

    #[Test]
    public function mainReleasesByThePolicyInQaConfig(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->project->write('qaConfig/qa.php', '<?php
return static fn (\LTS\PHPQA\Pipeline\Config\QaConfigBuilder $qa) => $qa->withReleaseVersionPolicy(' . ReleaseVersionPolicy::class . '::semanticVersioning(\'v\'));
');

        self::assertSame(0, ChangelogReleaseCommand::main($this->project->path, $this->stdout, $this->stderr, self::NOTES, 'v85.0.0'), $this->stderr->fetch());
        self::assertSame(self::OLD_NOTES, $this->stdout->fetch());
    }

    #[Test]
    public function mainFailsOnAConfigItCannotRead(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->project->write('qaConfig/qa.php', "<?php\nreturn 1;\n");

        self::assertSame(1, ChangelogReleaseCommand::main($this->project->path, $this->stdout, $this->stderr, self::NOTES, self::V850));
        self::assertStringContainsString('cannot read the release policy from qaConfig/qa.php', $this->stderr->fetch());
        self::assertSame('', $this->stdout->fetch());
    }

    #[Test]
    public function nextVersionPrintsNothingWhenNoReleaseIsDue(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::EMPTY);

        self::assertSame(0, $this->invoke(self::NEXT_VERSION));
        self::assertSame('', $this->stdout->fetch());
        self::assertStringContainsString('No release is due: "## Unreleased" has no entries.', $this->stderr->fetch());
        self::assertSame([], $this->processes->specs);
    }

    #[Test]
    public function anInvalidChangelogFailsWithItsProblemsAndTheIdentifier(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "## Unreleased\n\n### Fixed\n\n### Fixed\n");

        self::assertSame(1, $this->invoke(self::NEXT_VERSION));
        self::assertSame('', $this->stdout->fetch());
        $stderr = $this->stderr->fetch();
        self::assertStringContainsString("CHANGELOG.md is invalid:\n  - line 3: \"### Fixed\" has no entries", $stderr);
        self::assertStringContainsString('phpqaci.changelog  (vendor/bin/rule-doc phpqaci.changelog)', $stderr);
    }

    #[Test]
    public function aMissingChangelogFails(): void
    {
        self::assertSame(1, $this->invoke(self::NEXT_VERSION));
        self::assertStringContainsString('no CHANGELOG.md in ' . $this->project->path, $this->stderr->fetch());
    }

    #[Test]
    public function aGitFailureFails(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->processes->willFail(128, 'fatal: not a git repository');

        self::assertSame(1, $this->invoke(self::NEXT_VERSION));
        self::assertStringContainsString('`git tag --list` failed (exit 128)', $this->stderr->fetch());
    }

    #[Test]
    public function applyRewritesTheChangelog(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);

        self::assertSame(0, $this->invoke(self::APPLY, self::VERSION, '2026-10-02'));
        self::assertStringStartsWith("# Changelog\n\n## Unreleased\n\n## 85.1.0 — 2026-10-02\n\n### Added\n\n- A feature.\n\n## 85.0.0", $this->project->read(ChangelogCheck::CHANGELOG));
        self::assertSame("CHANGELOG.md: released \"## Unreleased\" as 85.1.0 — 2026-10-02.\n", $this->stderr->fetch());
        self::assertSame('', $this->stdout->fetch());
    }

    #[Test]
    public function applyRefusesWhatTheWriterRefuses(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::EMPTY);

        self::assertSame(1, $this->invoke(self::APPLY, self::VERSION, '2026-10-02'));
        self::assertStringContainsString('there is nothing to release', $this->stderr->fetch());
        self::assertSame(self::EMPTY, $this->project->read(ChangelogCheck::CHANGELOG));
    }

    #[Test]
    public function notesPrintsTheTagAnnotation(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);

        self::assertSame(0, $this->invoke(self::NOTES, self::V850));
        self::assertSame("85.0.0 — 2026-09-01\n\nFixed\n-----\n\n- Old.\n", $this->stdout->fetch());
    }

    #[Test]
    public function notesForAnUnreleasedVersionFails(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);

        self::assertSame(1, $this->invoke(self::NOTES, '85.9.9'));
        self::assertStringContainsString('no "## 85.9.9" section', $this->stderr->fetch());
    }

    #[Test]
    public function pendingTagsNamesEachUntaggedReleaseAndTheCommitThatWroteItOldestFirst(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, "# Changelog\n\n## Unreleased\n\n## 85.2.0 — 2026-10-16\n\n### Fixed\n\n- Two.\n\n## 85.1.0 — 2026-10-09\n\n### Added\n\n- One.\n\n" . substr(self::EMPTY, \strlen("# Changelog\n\n## Unreleased\n\n")));
        $this->processes->willSucceed("85.0.0\n")->willSucceed("aaa111\n")->willSucceed("bbb222\n");

        self::assertSame(0, $this->invoke(self::PENDING_TAGS));
        self::assertSame("85.1.0 aaa111\n85.2.0 bbb222\n", $this->stdout->fetch());
        self::assertSame([
            self::TAG_LIST,
            "git log -n1 --format=%H '-S## 85.1.0 — 2026-10-09' HEAD -- CHANGELOG.md",
            "git log -n1 --format=%H '-S## 85.2.0 — 2026-10-16' HEAD -- CHANGELOG.md",
        ], $this->processes->commandLines());
    }

    #[Test]
    public function pendingTagsPrintsNothingWhenEveryReleaseIsTagged(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->processes->willSucceed("85.0.0\n");

        self::assertSame(0, $this->invoke(self::PENDING_TAGS));
        self::assertSame('', $this->stdout->fetch());
        self::assertSame([self::TAG_LIST], $this->processes->commandLines());
    }

    #[Test]
    public function pendingTagsRefusesAReleaseNoCommitWrote(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);
        $this->processes->willSucceed("84.9.0\n")->willSucceed("\n");

        self::assertSame(1, $this->invoke(self::PENDING_TAGS));
        self::assertSame('', $this->stdout->fetch());
        self::assertStringContainsString('no commit reachable from HEAD wrote "## 85.0.0 — 2026-09-01" into CHANGELOG.md; commit the release before tagging it', $this->stderr->fetch());
    }

    #[Test]
    public function addToolUpdatesRecordsEveryPinnedVersionThatMovedSinceHead(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::EMPTY);
        $this->project->write('phive.xml', self::PHPSTAN_PIN);
        $this->project->write('build/rector/composer.json', '{"require": {"rector/rector": "@stable"}}');
        $this->project->write('build/rector/composer.lock', '{"packages": [{"name": "rector/rector", "version": "2.6.6"}]}');
        $this->processes
            ->willSucceed()->willSucceed('<phar name="phpstan" installed="2.2.15"/>')
            ->willFail(128)
            ->willSucceed()->willSucceed('{"require": {"rector/rector": "@stable"}}')
            ->willSucceed()->willSucceed('{"packages": [{"name": "rector/rector", "version": "2.6.6"}]}')
        ;

        self::assertSame(0, $this->invoke(self::ADD_TOOL_UPDATES));
        self::assertStringContainsString("## Unreleased\n\n### Changed\n\n- **Bundled tool versions updated** by the weekly dependency update: phpstan 2.2.15 → 2.2.16.\n\n## 85.0.0", $this->project->read(ChangelogCheck::CHANGELOG));
        self::assertSame('git cat-file -e HEAD:build/rector/composer.lock', $this->processes->commandLines()[5]);
    }

    #[Test]
    public function addToolUpdatesAddsNothingWhenNoVersionMoved(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::EMPTY);
        $this->project->write('phive.xml', self::PHPSTAN_PIN);
        $this->processes->willSucceed()->willSucceed(self::PHPSTAN_PIN)->willFail(128);

        self::assertSame(0, $this->invoke(self::ADD_TOOL_UPDATES));
        self::assertSame(self::EMPTY, $this->project->read(ChangelogCheck::CHANGELOG));
        self::assertSame("No bundled tool version differs from HEAD; nothing to record.\n", $this->stderr->fetch());
    }

    #[Test]
    public function addEntryWritesTheEntryUnderItsHeading(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);

        self::assertSame(0, $this->invoke(self::ADD_ENTRY, 'changed', 'Bundled tools updated.'));
        self::assertStringContainsString("### Added\n\n- A feature.\n\n### Changed\n\n- Bundled tools updated.\n\n## 85.0.0", $this->project->read(ChangelogCheck::CHANGELOG));
        self::assertSame("CHANGELOG.md: added an entry under \"### Changed\".\n", $this->stderr->fetch());
    }

    #[Test]
    public function addEntryRefusesAnUnknownHeading(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::CHANGELOG);

        self::assertSame(1, $this->invoke(self::ADD_ENTRY, 'improved', 'Something.'));
        self::assertStringContainsString('Unknown changelog heading "improved"', $this->stderr->fetch());
        self::assertSame(self::CHANGELOG, $this->project->read(ChangelogCheck::CHANGELOG));
    }

    #[Test]
    public function anUnknownCommandOrTheWrongArgumentCountPrintsTheUsage(): void
    {
        foreach ([[], ['bogus'], [self::NEXT_VERSION, self::EXTRA], [self::APPLY, self::VERSION], [self::NOTES], [self::ADD_ENTRY, 'fixed'], [self::PENDING_TAGS, self::EXTRA], [self::ADD_TOOL_UPDATES, self::EXTRA]] as $arguments) {
            self::assertSame(1, $this->invoke(...$arguments), implode(' ', $arguments));
            self::assertStringContainsString('Usage: changelog-release <command>', $this->stderr->fetch());
        }

        self::assertSame([], $this->processes->specs);
    }

    #[Test]
    public function theUsageEndsWithOneNewlineAndNoBlankLine(): void
    {
        $this->invoke('bogus');

        self::assertMatchesRegularExpression('/\S\n\z/', $this->stderr->fetch());
    }

    #[Test]
    public function mainWiresTheRealProcessRunner(): void
    {
        $this->project->write(ChangelogCheck::CHANGELOG, self::EMPTY);

        self::assertSame(0, ChangelogReleaseCommand::main($this->project->path, $this->stdout, $this->stderr, self::NEXT_VERSION));
        self::assertSame('', $this->stdout->fetch());
    }

    private function invoke(string ...$arguments): int
    {
        $root = $this->project->path;

        return new ChangelogReleaseCommand($root, new ChangelogGit($this->processes, $root), $this->stdout, $this->stderr)->run(...$arguments);
    }

    private function invokeUnder(ReleaseVersionPolicy $policy, string ...$arguments): int
    {
        $root = $this->project->path;

        return new ChangelogReleaseCommand($root, new ChangelogGit($this->processes, $root), $this->stdout, $this->stderr, $policy)->run(...$arguments);
    }
}
