<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(LogArchiver::class)]
#[Small]
final class LogArchiverTest extends TestCase
{
    private const string PHPSTAN_LOG = 'phpstan.log';

    private const string LIVE_LOG = 'live';

    private const string PHPSTAN = 'PHPStan';

    private const string TIMESTAMP = '20260908-120000';

    private const string ARCHIVE_AT_TIMESTAMP = 'phpstan.20260908-120000.log';

    private const int MAX_FILE_NAME_BYTES = 255;

    private TempDir $logDir;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->logDir = TempDir::create('phpqa-logs');
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->logDir->remove();
    }

    #[Test]
    public function aFullSuiteRunCopiesTheLiveLogToATimestampedArchiveAndKeepsTheLiveFile(): void
    {
        $this->logDir->write(self::PHPSTAN_LOG, self::LIVE_LOG);

        new LogArchiver($this->output)->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, false, ['/p/src'], self::TIMESTAMP);

        self::assertSame([self::ARCHIVE_AT_TIMESTAMP, self::PHPSTAN_LOG], $this->logDir->files());
        self::assertSame(self::LIVE_LOG, $this->logDir->read(self::ARCHIVE_AT_TIMESTAMP));
        self::assertStringContainsString("Full test suite run\nLog: phpstan.20260908-120000.log\n", $this->output->fetch());
    }

    #[Test]
    public function aPathSpecificRunEmbedsTheSanitisedPathsInTheArchiveName(): void
    {
        $this->logDir->write('phpunit.junit.xml', '<x/>');

        new LogArchiver($this->output)->archive('PHPUnit', $this->logDir->path, 'phpunit.junit.xml', true, ['src/Entity', 'tests/Unit'], self::TIMESTAMP);

        self::assertContains('phpunit.junit.src_Entity_tests_Unit.20260908-120000.xml', $this->logDir->files());
        self::assertStringContainsString('Path-specific run: src/Entity tests/Unit', $this->output->fetch());
    }

    #[Test]
    public function aPathSpecificRunOverARealisticSetOfLongPathsStillGetsAnArchiveTheFilesystemAccepts(): void
    {
        $this->logDir->write(self::PHPSTAN_LOG, self::LIVE_LOG);

        new LogArchiver($this->output)->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, true, $this->longPaths('a'), self::TIMESTAMP);

        $files = $this->logDir->files();
        self::assertCount(2, $files, 'the live log and one archive');
        foreach ($files as $file) {
            self::assertLessThanOrEqual(self::MAX_FILE_NAME_BYTES, \strlen($file), 'a name the filesystem would refuse is a failed copy: ' . $file);
        }
    }

    #[Test]
    public function twoLongPathSetsThatShareALongPrefixGetDifferentArchivesAndTheSameSetGetsTheSameName(): void
    {
        $this->logDir->write(self::PHPSTAN_LOG, self::LIVE_LOG);
        $archiver = new LogArchiver($this->output);

        $archiver->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, true, $this->longPaths('a'), self::TIMESTAMP);
        $archiver->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, true, $this->longPaths('b'), self::TIMESTAMP);
        $afterTwoSets = $this->logDir->files();
        self::assertCount(3, $afterTwoSets, 'the live log and one archive per distinct path set');

        $archiver->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, true, $this->longPaths('a'), self::TIMESTAMP);
        self::assertEqualsCanonicalizing($afterTwoSets, $this->logDir->files(), 'the same set at the same timestamp is the same archive');
    }

    #[Test]
    public function aLongPathSetKeepsTheNewestTenArchivesLikeAnyOtherPattern(): void
    {
        $this->logDir->write(self::PHPSTAN_LOG, self::LIVE_LOG);
        $archiver = new LogArchiver($this->output);

        for ($i = 1; $i <= 12; ++$i) {
            $archiver->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, true, $this->longPaths('a'), \sprintf('202609%02d-000000', $i));
            foreach ($this->logDir->files() as $file) {
                \Safe\touch($this->logDir->path . '/' . $file, 1_700_000_000 + $i);
            }
        }

        self::assertCount(11, $this->logDir->files(), 'ten archives of the one pattern and the live log');
        self::assertStringContainsString('Cleaned up', $this->output->fetch());
    }

    #[Test]
    public function onlyTheNewestTenArchivesPerPatternAreKeptAndPathArchivesAreNotEvictedByFullRuns(): void
    {
        $this->logDir->write(self::PHPSTAN_LOG, self::LIVE_LOG);
        for ($i = 1; $i <= 11; ++$i) {
            $file = $this->logDir->write(\sprintf('phpstan.202609%02d-000000.log', $i), (string)$i);
            \Safe\touch($file, 1_700_000_000 + $i);
        }

        $this->logDir->write('phpstan.src.20260101-000000.log', 'path run');

        new LogArchiver($this->output)->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, false, [], '20261231-000000');

        $remaining = $this->logDir->files();
        self::assertCount(12, $remaining, 'ten full-suite archives, the untouched path archive and the live log');
        self::assertNotContains('phpstan.20260901-000000.log', $remaining, 'the oldest full-suite archives are evicted');
        self::assertNotContains('phpstan.20260902-000000.log', $remaining);
        self::assertContains('phpstan.src.20260101-000000.log', $remaining);
        self::assertContains('phpstan.20261231-000000.log', $remaining);
        self::assertStringContainsString('Cleaned up 2 old log(s)', $this->output->fetch());
    }

    #[Test]
    public function aMissingLiveLogIsANoOp(): void
    {
        new LogArchiver($this->output)->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, false, []);

        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->logDir->files());
    }

    #[Test]
    public function archivesBeyondTheDirectoryCapArePrunedOldestFirst(): void
    {
        // Every -p run mints its own retention pattern, so per-pattern retention
        // alone cannot bound the directory: 120 distinct one-off path archives
        // are 120 patterns holding one file each, all under their own limit.
        $this->logDir->write(self::PHPSTAN_LOG, self::LIVE_LOG);
        for ($i = 1; $i <= 120; ++$i) {
            $file = $this->logDir->write(\sprintf('phpstan.path_%03d.20260101-000000.log', $i), (string)$i);
            \Safe\touch($file, 1_700_000_000 + $i);
        }

        new LogArchiver($this->output)->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, false, [], self::TIMESTAMP);

        $remaining = $this->logDir->files();
        self::assertContains(self::PHPSTAN_LOG, $remaining, 'the live log is never an archive and is never pruned');
        self::assertContains(self::ARCHIVE_AT_TIMESTAMP, $remaining, 'the archive this run just wrote survives');
        self::assertNotContains('phpstan.path_001.20260101-000000.log', $remaining, 'the oldest archives go first');
        self::assertCount(
            LogArchiver::DIRECTORY_CAP + 1,
            $remaining,
            'the cap bounds the archives; the live log sits outside it',
        );
        self::assertStringContainsString('Pruned', $this->output->fetch());
    }

    #[Test]
    public function unrelatedFilesAreNeitherCountedNorPruned(): void
    {
        $this->logDir->write(self::PHPSTAN_LOG, self::LIVE_LOG);
        for ($i = 0; $i < 120; ++$i) {
            $this->logDir->write(\sprintf('other.%03d.log', $i), 'x');
        }

        new LogArchiver($this->output)->archive(self::PHPSTAN, $this->logDir->path, self::PHPSTAN_LOG, false, [], self::TIMESTAMP);

        self::assertContains('other.000.log', $this->logDir->files(), 'another tool owns these; pruning them is not ours to do');
        self::assertStringNotContainsString('Pruned', $this->output->fetch());
    }

    /**
     * The paths a `-p` run of a consuming project covers: absolute, deep, and several of them, which
     * together are far longer than a file name may be. Sets built with different tags share a long prefix.
     *
     * @return list<string>
     */
    private function longPaths(string $tag): array
    {
        $root = '/var/www/vhosts/example/consuming-project';

        return [
            $root . '/migrations/drafts/a_file_with_a_rather_long_descriptive_name_for_a_migration.php',
            $root . '/migrations',
            $root . '/qaConfig/PHPStan',
            $root . '/qaConfig/qa.php',
            $root . '/config',
            $root . '/public',
            $root . '/bin/' . $tag,
        ];
    }
}
