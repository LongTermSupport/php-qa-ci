<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support\ProjectTreeLeak;

use LTS\PHPQA\Tests\Support\ProjectTreeLeak\ProjectTreeLedger;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The probe behind ProjectTreeLeakExtension: a path that appears in the
 * project tree during a test, outside the entries the suite writes to on
 * purpose, is charged to that test and moved out of the tree; nothing that
 * was there before the test is ever touched (issue #132).
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class ProjectTreeLedgerTest extends TestCase
{
    private TempDir $directory;

    private string $root;

    private string $quarantine;

    protected function setUp(): void
    {
        $this->directory  = TempDir::create('project-tree-ledger');
        $this->root       = $this->directory->mkdir('project');
        $this->quarantine = $this->directory->path . '/project/var/qa/project-tree-leaks/123';
        $this->directory->write('project/composer.json', '{}');
        $this->directory->write('project/src/Existing.php', '<?php');
        $this->directory->write('project/tests/ExistingTest.php', '<?php');
        $this->directory->write('project/vendor/autoload.php', '<?php');
        $this->directory->mkdir('project/var');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    #[Test]
    public function aTestThatWritesNothingIntoTheTreeIsNotRecorded(): void
    {
        $ledger = $this->ledger();

        $ledger->sweep('FooTest::clean');

        self::assertSame([], $ledger->leaks());
    }

    /** The two escapes issue #132 found: a Turbo fixture and a `php://` target made relative. */
    #[Test]
    public function newTopLevelEntriesAreChargedToTheTestAndMovedOutOfTheTree(): void
    {
        $ledger = $this->ledger();
        $this->directory->write('project/linux-gnu-x86_64/phpstan_turbo_core.so', 'ELF');
        $this->directory->mkdir('project/php:');

        $ledger->sweep('FooTest::leaky');
        $ledger->sweep('FooTest::next');

        self::assertSame(
            [\sprintf('FooTest::leaky wrote linux-gnu-x86_64, php: into the project tree (moved to %s/1)', $this->quarantine)],
            $ledger->leaks(),
        );
        self::assertFileDoesNotExist($this->root . '/linux-gnu-x86_64');
        self::assertFileDoesNotExist($this->root . '/php:');
        self::assertSame('ELF', \Safe\file_get_contents($this->quarantine . '/1/linux-gnu-x86_64/phpstan_turbo_core.so'), 'kept for inspection, not destroyed');
        self::assertDirectoryExists($this->quarantine . '/1/php:');
    }

    #[Test]
    public function aNewEntryInsideAWatchedDirectoryIsChargedAndMoved(): void
    {
        $ledger = $this->ledger();
        $this->directory->write('project/src/Leaked.php', '<?php');

        $ledger->sweep('FooTest::writesIntoSrc');

        self::assertSame(
            [\sprintf('FooTest::writesIntoSrc wrote src/Leaked.php into the project tree (moved to %s/1)', $this->quarantine)],
            $ledger->leaks(),
        );
        self::assertFileDoesNotExist($this->root . '/src/Leaked.php');
        self::assertFileExists($this->root . '/src/Existing.php');
        self::assertFileExists($this->quarantine . '/1/src/Leaked.php');
    }

    #[Test]
    public function whatWasThereBeforeTheTestIsNeverTouched(): void
    {
        $this->directory->write('project/untracked-but-present.txt', 'mine');
        $ledger = $this->ledger();
        $this->directory->write('project/stray.txt', '');

        $ledger->sweep('FooTest::leaky');

        self::assertSame('mine', \Safe\file_get_contents($this->root . '/untracked-but-present.txt'));
        self::assertSame('<?php', \Safe\file_get_contents($this->root . '/src/Existing.php'));
        self::assertFileExists($this->root . '/composer.json');
        self::assertFileDoesNotExist($this->root . '/stray.txt');
    }

    /** var/ is the pipeline's own output and vendor/ is Composer's; neither is the tree under test. */
    #[Test]
    public function anUnwatchedEntryIsNeitherChargedNorMoved(): void
    {
        $ledger = $this->ledger();
        $this->directory->write('project/var/qa/phpunit.cache/test-results', '{}');
        $this->directory->write('project/vendor/composer/installed.php', '<?php');

        $ledger->sweep('FooTest::writesTheCache');

        self::assertSame([], $ledger->leaks());
        self::assertFileExists($this->root . '/var/qa/phpunit.cache/test-results');
        self::assertFileExists($this->root . '/vendor/composer/installed.php');
    }

    #[Test]
    public function aNewDirectoryIsNamedOnceNotOncePerEntryInIt(): void
    {
        $ledger = $this->ledger();
        $this->directory->write('project/build-output/a.txt', '');
        $this->directory->write('project/build-output/b.txt', '');

        $ledger->sweep('FooTest::leaky');

        self::assertSame(
            [\sprintf('FooTest::leaky wrote build-output into the project tree (moved to %s/1)', $this->quarantine)],
            $ledger->leaks(),
        );
    }

    /** A redirected write can as well delete: that is charged too, once, though it cannot be undone here. */
    #[Test]
    public function anEntryThatVanishesDuringATestIsChargedOnce(): void
    {
        $ledger = $this->ledger();
        \Safe\unlink($this->root . '/src/Existing.php');

        $ledger->sweep('FooTest::deletes');
        $ledger->sweep('FooTest::next');

        self::assertSame(['FooTest::deletes deleted src/Existing.php from the project tree'], $ledger->leaks());
    }

    #[Test]
    public function eachChargedTestGetsItsOwnQuarantine(): void
    {
        $ledger = $this->ledger();
        $this->directory->write('project/first.txt', '');
        $ledger->sweep('FooTest::one');
        $this->directory->write('project/second.txt', '');
        $ledger->sweep('FooTest::two');

        self::assertSame(
            [
                \sprintf('FooTest::one wrote first.txt into the project tree (moved to %s/1)', $this->quarantine),
                \sprintf('FooTest::two wrote second.txt into the project tree (moved to %s/2)', $this->quarantine),
            ],
            $ledger->leaks(),
        );
        self::assertFileExists($this->quarantine . '/1/first.txt');
        self::assertFileExists($this->quarantine . '/2/second.txt');
    }

    private function ledger(): ProjectTreeLedger
    {
        return new ProjectTreeLedger($this->root, $this->quarantine, 'var', 'vendor');
    }
}
