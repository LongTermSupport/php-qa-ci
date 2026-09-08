<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\LogArchiver;
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
#[CoversClass(LogArchiver::class)]
#[UsesClass(TempDir::class)]
#[Small]
final class LogArchiverTest extends TestCase
{
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
        $this->logDir->write('phpstan.log', 'live');

        new LogArchiver($this->output)->archive('PHPStan', $this->logDir->path, 'phpstan.log', false, ['/p/src'], '20260908-120000');

        self::assertSame(['phpstan.20260908-120000.log', 'phpstan.log'], $this->logDir->files());
        self::assertSame('live', $this->logDir->read('phpstan.20260908-120000.log'));
        self::assertStringContainsString("Full test suite run\nLog: phpstan.20260908-120000.log\n", $this->output->fetch());
    }

    #[Test]
    public function aPathSpecificRunEmbedsTheSanitisedPathsInTheArchiveName(): void
    {
        $this->logDir->write('phpunit.junit.xml', '<x/>');

        new LogArchiver($this->output)->archive('PHPUnit', $this->logDir->path, 'phpunit.junit.xml', true, ['src/Entity', 'tests/Unit'], '20260908-120000');

        self::assertContains('phpunit.junit.src_Entity_tests_Unit.20260908-120000.xml', $this->logDir->files());
        self::assertStringContainsString('Path-specific run: src/Entity tests/Unit', $this->output->fetch());
    }

    #[Test]
    public function onlyTheNewestTenArchivesPerPatternAreKeptAndPathArchivesAreNotEvictedByFullRuns(): void
    {
        $this->logDir->write('phpstan.log', 'live');
        for ($i = 1; $i <= 11; ++$i) {
            $file = $this->logDir->write(\sprintf('phpstan.202609%02d-000000.log', $i), (string)$i);
            \Safe\touch($file, 1_700_000_000 + $i);
        }

        $this->logDir->write('phpstan.src.20260101-000000.log', 'path run');

        new LogArchiver($this->output)->archive('PHPStan', $this->logDir->path, 'phpstan.log', false, [], '20261231-000000');

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
        new LogArchiver($this->output)->archive('PHPStan', $this->logDir->path, 'phpstan.log', false, []);

        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->logDir->files());
    }

    #[Test]
    public function moreThanAHundredLogsInTheDirectoryTriggersAWarning(): void
    {
        $this->logDir->write('phpstan.log', 'live');
        for ($i = 0; $i < 101; ++$i) {
            $this->logDir->write(\sprintf('other.%03d.log', $i), 'x');
        }

        new LogArchiver($this->output)->archive('PHPStan', $this->logDir->path, 'phpstan.log', false, [], '20260908-120000');

        self::assertStringContainsString('WARNING: HIGH LOG FILE COUNT', $this->output->fetch());
    }
}
