<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
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
#[Small]
final class InfectionDiffFilterTest extends TestCase
{
    private const string COMMITTED = 'src/Committed.php';

    private const string MIRRORED_SOURCE = 'src/Lane/Tool.php';

    private const string MIRRORING_TEST = 'tests/Small/Lane/ToolTest.php';

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
        $args = new InfectionDiffFilter()->gitDiffArguments('origin/main', '/p/src', '/p/tests');

        self::assertSame(['--no-pager', 'diff', 'origin/main...HEAD', '-z', '-M', '--name-status', '--diff-filter=AMRCD', '--relative', '--', '/p/src', '/p/tests'], $args);
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
        $filter = $this->filter($this->records(['M', 'tests/Support/Helper.php'], ['A', 'tests/fixtures/data.json']));

        self::assertTrue($filter->isEmpty());
        self::assertSame(['tests/Support/Helper.php', 'tests/fixtures/data.json'], $filter->unmappedTests);
    }

    #[Test]
    public function aChangedFileUnderAnIgnoredPathIsLeftOut(): void
    {
        $this->project->write('src/Legacy/Old.php', '<?php');
        $filter = $this->filter(
            $this->records(['M', 'src/Legacy/Old.php'], ['M', 'src/Kept.php'], ['A', 'src/LegacyExtra/New.php'], ['M', 'src/Domain/Legacy/Deep.php'], ['M', 'tests/Legacy/OldTest.php']),
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

    private function filter(string $output, ?IgnoredPaths $ignored = null): InfectionDiffFilterDto
    {
        return new InfectionDiffFilter()->fromGitDiffOutput($output, $this->cwd, $this->cwd . '/src', $this->cwd . '/tests', $ignored ?? new IgnoredPaths($this->cwd));
    }

    /** @param list<string> ...$records `git diff -z --name-status` output for the given records */
    private function records(array ...$records): string
    {
        return implode('', array_map(static fn (array $record): string => implode("\0", $record) . "\0", $records));
    }
}
