<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use Closure;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Infection\CommentOnlyChange;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffFilterDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffFilter;
use LTS\PHPQA\Pipeline\Lane\Infection\TestSourceMirror;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InfectionDiffFilter::class)]
#[CoversClass(InfectionDiffFilterDto::class)]
#[UsesClass(IgnoredPaths::class)]
#[UsesClass(TestSourceMirror::class)]
#[UsesClass(CommentOnlyChange::class)]
#[Small]
final class InfectionDiffFilterTest extends TestCase
{
    private const string COMMITTED = 'src/Committed.php';

    private const string ADDED = 'src/Added.php';

    private const string MIRRORED_SOURCE = 'src/Lane/Tool.php';

    private const string MIRRORING_TEST = 'tests/Small/Lane/ToolTest.php';

    private const string SRC_SPEC = '/p/src';

    private const string TESTS_SPEC = '/p/tests';

    private const string PHPUNIT_XML = 'qaConfig/phpunit.xml';

    private const string HELPER = 'tests/Support/Helper.php';

    private const string CODE = "<?php\n\nfinal class Committed\n{\n    public function one(): int\n    {\n        return 1;\n    }\n}\n";

    private const string CODE_DOCUMENTED = "<?php\n\n/** A committed class. */\nfinal class Committed\n{\n    /** @return int one */\n    public function one(): int\n    {\n        return 1; // always\n    }\n}\n";

    private TempDir $project;

    private string $cwd;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-diff-filter');
        $this->cwd     = $this->project->path;
        $this->project->write(self::MIRRORED_SOURCE, '<?php');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theGitDiffIsAThreeDotNulSeparatedRenameAwareDiffOfCommittedHistory(): void
    {
        $args = new InfectionDiffFilter()->gitDiffArguments('origin/main', self::SRC_SPEC, self::TESTS_SPEC);

        self::assertSame(['--no-pager', 'diff', 'origin/main...HEAD', '-z', '-M', '--name-status', '--diff-filter=AMRCD', '--relative', '--', self::SRC_SPEC, self::TESTS_SPEC], $args);
    }

    #[Test]
    public function theConfigurationPathsAreDiffedAlongsideTheSourceAndTests(): void
    {
        $args = new InfectionDiffFilter()->gitDiffArguments('origin/main', self::SRC_SPEC, self::TESTS_SPEC, '/p/qaConfig', '/p/composer.lock');

        self::assertSame(['--', self::SRC_SPEC, self::TESTS_SPEC, '/p/qaConfig', '/p/composer.lock'], \array_slice($args, -5));
    }

    #[Test]
    public function aChangedFullRunTriggerIsReportedAsSuchAndMutatesNothing(): void
    {
        $filter = $this->filter($this->records(['M', self::PHPUNIT_XML], ['M', self::COMMITTED]), null, $this->cwd . '/' . self::PHPUNIT_XML);

        self::assertSame([self::PHPUNIT_XML], $filter->configChanges);
        self::assertSame([self::COMMITTED], $filter->relativePaths);
    }

    #[Test]
    public function aChangedFileThatIsNeitherSourceTestNorTriggerIsLeftOutQuietly(): void
    {
        $filter = $this->filter($this->records(['M', 'composer.lock'], ['M', 'qaConfig/qa.php'], ['M', self::COMMITTED]), null, $this->cwd . '/' . self::PHPUNIT_XML);

        self::assertSame([], $filter->configChanges, 'composer.lock and qa.php decide neither what is mutated nor whether a mutant is killed');
        self::assertSame([], $filter->unmappedTests);
        self::assertSame([self::COMMITTED], $filter->relativePaths);
    }

    #[Test]
    public function aBootstrapUnderTheTestsDirectoryIsATriggerNotAnUnmappedTest(): void
    {
        $filter = $this->filter($this->records(['M', 'tests/bootstrap.php']), null, $this->cwd . '/tests/bootstrap.php');

        self::assertSame(['tests/bootstrap.php'], $filter->configChanges);
        self::assertSame([], $filter->unmappedTests);
    }

    #[Test]
    public function aDocblockOnlySourceChangeIsListedNotMutated(): void
    {
        $this->project->write(self::COMMITTED, self::CODE_DOCUMENTED);
        $filter = $this->filter($this->records(['M', self::COMMITTED]), base: $this->base([self::COMMITTED => self::CODE]));

        self::assertTrue($filter->isEmpty());
        self::assertSame([self::COMMITTED], $filter->commentOnly);
    }

    #[Test]
    public function aRealStatementIsMutated(): void
    {
        $this->project->write(self::COMMITTED, str_replace('return 1;', 'return 2;', self::CODE));
        $filter = $this->filter($this->records(['M', self::COMMITTED]), base: $this->base([self::COMMITTED => self::CODE]));

        self::assertSame([self::COMMITTED], $filter->relativePaths);
        self::assertSame([], $filter->commentOnly);
    }

    #[Test]
    public function aRenamedFileIsComparedWithItsOldPathAtTheBase(): void
    {
        $this->project->write(self::ADDED, self::CODE_DOCUMENTED);
        $filter = $this->filter($this->records(['R091', self::COMMITTED, self::ADDED]), base: $this->base([self::COMMITTED => self::CODE]));

        self::assertTrue($filter->isEmpty());
        self::assertSame([self::ADDED], $filter->commentOnly);
    }

    #[Test]
    public function anAddedFileAlwaysCounts(): void
    {
        $this->project->write(self::ADDED, self::CODE);
        $filter = $this->filter($this->records(['A', self::ADDED]), base: $this->base([self::ADDED => self::CODE]));

        self::assertSame([self::ADDED], $filter->relativePaths);
    }

    #[Test]
    public function aFileUnreadableAtTheBaseCounts(): void
    {
        $this->project->write(self::COMMITTED, self::CODE);
        $filter = $this->filter($this->records(['M', self::COMMITTED]), base: $this->base([]));

        self::assertSame([self::COMMITTED], $filter->relativePaths, 'when the base cannot be read the change is mutated, never assumed harmless');
    }

    #[Test]
    public function aCommentOnlyTestChangeBringsInNothing(): void
    {
        $this->project->write(self::MIRRORING_TEST, self::CODE_DOCUMENTED);
        $filter = $this->filter($this->records(['M', self::MIRRORING_TEST], ['M', 'tests/Support/Helper.php']), base: $this->base([self::MIRRORING_TEST => self::CODE]));

        self::assertSame([], $filter->relativePaths);
        self::assertSame([], $filter->mirrored);
        self::assertSame([self::MIRRORING_TEST], $filter->commentOnly);
        self::assertSame(['tests/Support/Helper.php'], $filter->unmappedTests);
    }

    #[Test]
    public function withNoBaseReaderEveryChangeCounts(): void
    {
        $this->project->write(self::COMMITTED, self::CODE);

        self::assertSame([self::COMMITTED], $this->filter($this->records(['M', self::COMMITTED]))->relativePaths);
    }

    #[Test]
    public function aDiffOfSourceAndTestsAloneHasNoConfigurationChange(): void
    {
        self::assertSame([], $this->filter($this->records(['M', self::COMMITTED], ['M', self::MIRRORING_TEST]))->configChanges);
    }

    #[Test]
    public function gitStatusRecordsBecomeNameStatusRecords(): void
    {
        $status = " M src/Edited.php\0?? src/New.php\0D  src/Gone.php\0R  src/Moved.php\0src/Old.php\0MM tests/Small/Lane/ToolTest.php\0";

        self::assertSame(
            $this->records(['M', 'src/Edited.php'], ['A', 'src/New.php'], ['D', 'src/Gone.php'], ['R', 'src/Old.php', 'src/Moved.php'], ['M', self::MIRRORING_TEST]),
            new InfectionDiffFilter()->nameStatusFromGitStatus($status),
        );
    }

    #[Test]
    public function anEmptyGitStatusIsAnEmptyNameStatus(): void
    {
        self::assertSame('', new InfectionDiffFilter()->nameStatusFromGitStatus(''));
    }

    #[Test]
    public function aWorkingTreeChangeIsScopedLikeACommittedOne(): void
    {
        $filter = $this->filter(new InfectionDiffFilter()->nameStatusFromGitStatus("?? src/New.php\0 D src/Gone.php\0"));

        self::assertSame(['src/New.php'], $filter->relativePaths);
    }

    #[Test]
    public function gitStatusPathsAreMadeRelativeToTheProjectInsideALargerRepository(): void
    {
        $status = " M app/src/Edited.php\0R  app/src/After.php\0app/src/Before.php\0?? app/composer.json\0";

        self::assertSame(
            $this->records(['M', 'src/Edited.php'], ['R', 'src/Before.php', 'src/After.php'], ['A', 'composer.json']),
            new InfectionDiffFilter()->nameStatusFromGitStatus($status, 'app/'),
        );
    }

    #[Test]
    public function aLaterDeletionTakesAPathTheCommittedDiffAddedOutOfScope(): void
    {
        $filter = $this->filter($this->records(['A', self::ADDED], ['M', self::COMMITTED], ['D', self::ADDED]));

        self::assertSame([self::COMMITTED], $filter->relativePaths, 'Infection aborts on a positional path that no longer exists');
    }

    #[Test]
    public function anUncommittedDeletionOfACommittedChangeLeavesNothingToMutate(): void
    {
        $filter = $this->filter($this->records(['M', self::COMMITTED]) . new InfectionDiffFilter()->nameStatusFromGitStatus(' D ' . self::COMMITTED . "\0"));

        self::assertTrue($filter->isEmpty());
    }

    #[Test]
    public function aStagedChangeDeletedFromTheWorkingTreeIsADeletion(): void
    {
        $filter = new InfectionDiffFilter();

        self::assertSame($this->records(['A', self::ADDED], ['D', self::ADDED]), $filter->nameStatusFromGitStatus('AD ' . self::ADDED . "\0"));
        self::assertSame($this->records(['M', self::COMMITTED], ['D', self::COMMITTED]), $filter->nameStatusFromGitStatus('MD ' . self::COMMITTED . "\0"));
        self::assertSame(
            $this->records(['R', self::COMMITTED, self::ADDED], ['D', self::ADDED]),
            $filter->nameStatusFromGitStatus('RD ' . self::ADDED . "\0" . self::COMMITTED . "\0"),
        );
    }

    #[Test]
    public function aStagedChangeDeletedFromTheWorkingTreeIsNeverMutated(): void
    {
        $status = 'AD ' . self::ADDED . "\0MD " . self::COMMITTED . "\0RD src/Moved.php\0src/Staged.php\0";
        $filter = $this->filter($this->records(['M', self::COMMITTED], ['M', 'src/Staged.php']) . new InfectionDiffFilter()->nameStatusFromGitStatus($status));

        self::assertTrue($filter->isEmpty(), 'every one of these paths is gone from disk, and Infection aborts on a missing positional path');
    }

    #[Test]
    public function aLaterRenameMovesTheOldPathOutOfScopeAndTheNewPathIn(): void
    {
        $filter = $this->filter($this->records(['M', self::COMMITTED], ['R', self::COMMITTED, self::ADDED]));

        self::assertSame([self::ADDED], $filter->relativePaths);
    }

    #[Test]
    public function aLaterCopyKeepsTheOriginalInScope(): void
    {
        $filter = $this->filter($this->records(['M', self::COMMITTED], ['C100', self::COMMITTED, self::ADDED]));

        self::assertSame([self::COMMITTED, self::ADDED], $filter->relativePaths);
    }

    #[Test]
    public function aPathDeletedThenAddedAgainIsInScope(): void
    {
        self::assertSame([self::ADDED], $this->filter($this->records(['D', self::ADDED], ['A', self::ADDED]))->relativePaths);
    }

    #[Test]
    public function aLaterDeletionOfAMirroredSourceTakesItOutOfScope(): void
    {
        self::assertTrue($this->filter($this->records(['M', self::MIRRORING_TEST], ['D', self::MIRRORED_SOURCE]))->isEmpty());
    }

    #[Test]
    public function theFilterIsExactlyTheCommittedChangeAbsolutisedAgainstTheCwd(): void
    {
        $filter = $this->filter($this->records(['M', self::COMMITTED]));

        self::assertSame([$this->cwd . '/' . self::COMMITTED], $filter->positionalPaths);
        self::assertSame(self::COMMITTED, $filter->display());
        self::assertFalse($filter->isEmpty());
        self::assertSame([], $filter->mirrored);
        self::assertSame([], $filter->unmappedTests);
    }

    #[Test]
    public function onlyPhpFilesSurviveAndTheDisplayListIsCommaJoined(): void
    {
        $filter = $this->filter($this->records(['A', 'src/A.php'], ['M', 'src/notes.md'], ['M', 'src/Deep/B.php'], ['A', 'src/c.phtml']));

        self::assertSame([$this->cwd . '/src/A.php', $this->cwd . '/src/Deep/B.php'], $filter->positionalPaths);
        self::assertSame('src/A.php,src/Deep/B.php', $filter->display());
    }

    #[Test]
    public function aRenamedOrCopiedSourceFileIsMutatedUnderItsNewPath(): void
    {
        $filter = $this->filter($this->records(['R087', 'src/Old.php', 'src/Moved/New.php'], ['C100', 'src/Original.php', 'src/Copy.php']));

        self::assertSame(['src/Moved/New.php', 'src/Copy.php'], $filter->relativePaths);
    }

    #[Test]
    public function aDeletedSourceFileHasNothingToMutate(): void
    {
        self::assertTrue($this->filter($this->records(['D', 'src/Gone.php']))->isEmpty());
    }

    #[Test]
    public function aPathWithSpacesTabsAndNonAsciiBytesSurvivesVerbatim(): void
    {
        $filter = $this->filter($this->records(['A', "src/Odd Name\tÜ.php"]));

        self::assertSame(["src/Odd Name\tÜ.php"], $filter->relativePaths);
    }

    #[Test]
    public function aChangedTestMutatesTheSourceItIsNamedAfter(): void
    {
        $filter = $this->filter($this->records(['M', self::MIRRORING_TEST]));

        self::assertSame([$this->cwd . '/' . self::MIRRORED_SOURCE], $filter->positionalPaths);
        self::assertSame([self::MIRRORED_SOURCE => self::MIRRORING_TEST], $filter->mirrored);
        self::assertSame([], $filter->unmappedTests);
    }

    #[Test]
    public function aDeletedTestStillMutatesTheSourceItPinned(): void
    {
        self::assertSame([self::MIRRORED_SOURCE], $this->filter($this->records(['D', self::MIRRORING_TEST]))->relativePaths);
    }

    #[Test]
    public function aRenamedTestMirrorsByItsNewNameElseItsOldOne(): void
    {
        $byNew = $this->filter($this->records(['R100', 'tests/Small/Lane/OldTest.php', self::MIRRORING_TEST]));
        $byOld = $this->filter($this->records(['R100', self::MIRRORING_TEST, 'tests/Small/Lane/RenamedTest.php']));

        self::assertSame([self::MIRRORED_SOURCE], $byNew->relativePaths);
        self::assertSame([self::MIRRORED_SOURCE], $byOld->relativePaths);
        self::assertSame([self::MIRRORED_SOURCE => 'tests/Small/Lane/RenamedTest.php'], $byOld->mirrored);
    }

    #[Test]
    public function aSourceChangedDirectlyIsNotListedAgainAsMirrored(): void
    {
        $filter = $this->filter($this->records(['M', self::MIRRORING_TEST], ['M', self::MIRRORED_SOURCE]));

        self::assertSame([self::MIRRORED_SOURCE], $filter->relativePaths);
        self::assertSame([], $filter->mirrored);
    }

    #[Test]
    public function twoTestsOfOneSourceMutateItOnce(): void
    {
        $filter = $this->filter($this->records(['M', self::MIRRORING_TEST], ['M', 'tests/Large/Lane/ToolTest.php']));

        self::assertSame([self::MIRRORED_SOURCE], $filter->relativePaths);
        self::assertSame([self::MIRRORED_SOURCE => self::MIRRORING_TEST], $filter->mirrored);
    }

    #[Test]
    public function aTestFileThatMirrorsNoSourceIsListedAsUnmapped(): void
    {
        $filter = $this->filter($this->records(['M', self::HELPER], ['A', 'tests/fixtures/data.json']));

        self::assertTrue($filter->isEmpty());
        self::assertSame([self::HELPER, 'tests/fixtures/data.json'], $filter->unmappedTests);
    }

    #[Test]
    public function aChangedFileUnderAnIgnoredPathIsLeftOut(): void
    {
        $this->project->write('src/Legacy/Old.php', '<?php');
        $filter = new InfectionDiffFilter()->fromGitDiffOutput(
            $this->records(['M', 'src/Legacy/Old.php'], ['M', 'src/Kept.php'], ['A', 'src/LegacyExtra/New.php'], ['M', 'src/Domain/Legacy/Deep.php'], ['M', 'tests/Legacy/OldTest.php']),
            $this->cwd,
            $this->cwd . '/src',
            $this->cwd . '/tests',
            new IgnoredPaths($this->cwd, 'src/Legacy'),
        );

        self::assertSame(['src/Kept.php', 'src/LegacyExtra/New.php', 'src/Domain/Legacy/Deep.php'], $filter->relativePaths, 'a test mirroring an ignored source does not bring it back');
        self::assertSame([], $filter->unmappedTests);
    }

    #[Test]
    public function anEmptyDiffIsEmpty(): void
    {
        $filter = $this->filter('');

        self::assertTrue($filter->isEmpty());
        self::assertSame('', $filter->display());
        self::assertSame([], $filter->positionalPaths);
        self::assertSame([], $filter->unmappedTests);
    }

    #[Test]
    public function aTruncatedRecordIsIgnored(): void
    {
        self::assertTrue($this->filter("M\0")->isEmpty());
        self::assertTrue($this->filter("R100\0src/Old.php\0")->isEmpty());
    }

    /** @param Closure(string): ?string|null $base */
    private function filter(string $output, ?Closure $base = null, string ...$triggers): InfectionDiffFilterDto
    {
        return new InfectionDiffFilter()->fromGitDiffOutput($output, $this->cwd, $this->cwd . '/src', $this->cwd . '/tests', new IgnoredPaths($this->cwd), $base, ...$triggers);
    }

    /**
     * @param array<string, string> $files project-relative path => its content at the base
     *
     * @return Closure(string): ?string
     */
    private function base(array $files): Closure
    {
        return static fn (string $path): ?string => $files[$path] ?? null;
    }

    /** @param list<string> ...$records `git diff -z --name-status` output for the given records */
    private function records(array ...$records): string
    {
        return implode('', array_map(static fn (array $record): string => implode("\0", $record) . "\0", $records));
    }
}
