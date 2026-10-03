<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support\TempLeak;

use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Tests\Support\TempLeak\TempLeakLedger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The probe behind TempLeakExtension: whatever a test leaves in the run's temp
 * directory is charged to that test and swept, so the next test starts clean
 * and is never blamed for it.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class TempLeakLedgerTest extends TestCase
{
    private TempDir $directory;

    protected function setUp(): void
    {
        $this->directory = TempDir::create('temp-leak-ledger');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    #[Test]
    public function aTestThatLeavesNothingIsNotRecorded(): void
    {
        $ledger = new TempLeakLedger($this->directory->path);

        $ledger->sweep('FooTest::clean');

        self::assertSame([], $ledger->leaks());
    }

    #[Test]
    public function whatATestLeavesIsChargedToItAndSwept(): void
    {
        $ledger = new TempLeakLedger($this->directory->path);
        $this->directory->write('phpqa-record-abc/qaConfig/phpstan.neon', "parameters:\n");
        $this->directory->write('stubNotice123', '');

        $ledger->sweep('FooTest::leaky');
        $ledger->sweep('FooTest::next');

        self::assertSame(['FooTest::leaky left phpqa-record-abc, stubNotice123'], $ledger->leaks());
        self::assertSame(['.', '..'], \Safe\scandir($this->directory->path));
    }

    /**
     * A file the process still holds open is in use, not left behind: PHP's phar extension
     * decompresses an entry into the temp directory and deletes it when the process exits.
     */
    #[Test]
    public function aFileStillHeldOpenIsNeitherChargedNorSwept(): void
    {
        $ledger = new TempLeakLedger($this->directory->path);
        $open   = $this->directory->write('phpAbc123', 'decompressed entry');

        $ledger->sweep('FooTest::readsAPhar', $open);

        self::assertSame([], $ledger->leaks());
        self::assertFileExists($open);
    }

    /** A cache a test harness keeps on purpose survives each test, and goes when the run does. */
    #[Test]
    public function aRetainedEntryIsNeitherChargedNorSweptUntilTheRunEnds(): void
    {
        $ledger = new TempLeakLedger($this->directory->path, 'phpstan-tests');
        $this->directory->write('phpstan-tests/cache/Container.php', '<?php');

        $ledger->sweep('FooRuleTest::fires');

        self::assertSame([], $ledger->leaks());
        self::assertDirectoryExists($this->directory->path . '/phpstan-tests');

        $ledger->clear();

        self::assertSame(['.', '..'], \Safe\scandir($this->directory->path));
    }
}
