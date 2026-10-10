<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support\ProjectTreeLeak;

use InvalidArgumentException;
use LTS\PHPQA\Tests\Support\ProjectTreeLeak\ProjectTreeLedger;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Tests\Support\VanishingDirectoryStreamWrapper;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

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
    private const string SOURCE = '<?php';

    private const string LEAKY = 'FooTest::leaky';

    private const string NEXT = 'FooTest::next';

    private const string EXISTING = '/src/Existing.php';

    private const string SRC = '/src';

    private TempDir $directory;

    private string $root;

    private string $quarantine;

    protected function setUp(): void
    {
        $this->directory  = TempDir::create('project-tree-ledger');
        $this->root       = $this->directory->mkdir('project');
        $this->quarantine = $this->directory->path . '/project/var/qa/project-tree-leaks/123';
        $this->directory->write('project/composer.json', '{}');
        $this->directory->write('project/src/Existing.php', self::SOURCE);
        $this->directory->write('project/tests/ExistingTest.php', self::SOURCE);
        $this->directory->write('project/vendor/autoload.php', self::SOURCE);
        $this->directory->mkdir('project/var');
        \Safe\stream_wrapper_register(VanishingDirectoryStreamWrapper::SCHEME, VanishingDirectoryStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        \Safe\stream_wrapper_unregister(VanishingDirectoryStreamWrapper::SCHEME);
        VanishingDirectoryStreamWrapper::reset();
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

        $ledger->sweep(self::LEAKY);
        $ledger->sweep(self::NEXT);

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
        $this->directory->write('project/src/Leaked.php', self::SOURCE);

        $ledger->sweep('FooTest::writesIntoSrc');

        self::assertSame(
            [\sprintf('FooTest::writesIntoSrc wrote src/Leaked.php into the project tree (moved to %s/1)', $this->quarantine)],
            $ledger->leaks(),
        );
        self::assertFileDoesNotExist($this->root . '/src/Leaked.php');
        self::assertFileExists($this->root . self::EXISTING);
        self::assertFileExists($this->quarantine . '/1/src/Leaked.php');
    }

    #[Test]
    public function whatWasThereBeforeTheTestIsNeverTouched(): void
    {
        $this->directory->write('project/untracked-but-present.txt', 'mine');
        $ledger = $this->ledger();
        $this->directory->write('project/stray.txt', '');

        $ledger->sweep(self::LEAKY);

        self::assertSame('mine', \Safe\file_get_contents($this->root . '/untracked-but-present.txt'));
        self::assertSame(self::SOURCE, \Safe\file_get_contents($this->root . self::EXISTING));
        self::assertFileExists($this->root . '/composer.json');
        self::assertFileDoesNotExist($this->root . '/stray.txt');
    }

    /** var/ is the pipeline's own output and vendor/ is Composer's; neither is the tree under test. */
    #[Test]
    public function anUnwatchedEntryIsNeitherChargedNorMoved(): void
    {
        $ledger = $this->ledger();
        $this->directory->write('project/var/qa/phpunit.cache/test-results', '{}');
        $this->directory->write('project/vendor/composer/installed.php', self::SOURCE);

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

        $ledger->sweep(self::LEAKY);

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
        \Safe\unlink($this->root . self::EXISTING);

        $ledger->sweep('FooTest::deletes');
        $ledger->sweep(self::NEXT);

        self::assertSame(['FooTest::deletes deleted src/Existing.php from the project tree'], $ledger->leaks());
    }

    /**
     * Another process can remove a directory between the sweep's is_dir() and its read. The
     * directory has gone, which is a deletion like any other: charged once, and neither an
     * exception nor a PHP warning, either of which fails a green run (issue #137).
     */
    #[Test]
    public function aDirectoryThatVanishesBetweenTheCheckAndTheReadIsChargedOnceAsDeleted(): void
    {
        $ledger = $this->ledger(root: VanishingDirectoryStreamWrapper::SCHEME . '://' . $this->root);
        VanishingDirectoryStreamWrapper::vanishOnOpen($this->root . self::SRC);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $ledger->sweep('FooTest::racesAnotherProcess');
            $ledger->sweep(self::NEXT);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame(['FooTest::racesAnotherProcess deleted src from the project tree'], $ledger->leaks());
        self::assertDirectoryDoesNotExist($this->root . self::SRC);
    }

    /** A directory that is still there but cannot be read is a real failure, and is not swallowed. */
    #[Test]
    public function aDirectoryThatCannotBeReadButIsStillThereIsAnError(): void
    {
        $ledger = $this->ledger(root: VanishingDirectoryStreamWrapper::SCHEME . '://' . $this->root);
        VanishingDirectoryStreamWrapper::refuseOpen($this->root . self::SRC);

        $this->expectException(UnexpectedValueException::class);

        $ledger->sweep('FooTest::cannotBeRead');
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

    /** A path that cannot be moved is still charged, with the reason, and the next sweep does not charge it again. */
    #[Test]
    public function aPathThatCannotBeMovedIsChargedWithTheReason(): void
    {
        $this->directory->write('project/var/occupied', '');
        $ledger = $this->ledger($this->root . '/var/occupied/leaks');
        $this->directory->write('project/stray.txt', '');

        $ledger->sweep(self::LEAKY);
        $ledger->sweep(self::NEXT);

        self::assertCount(1, $ledger->leaks());
        self::assertStringStartsWith(
            \sprintf('FooTest::leaky wrote stray.txt into the project tree (moved to %s/var/occupied/leaks/1); could not move stray.txt: ', $this->root),
            $ledger->leaks()[0],
        );
    }

    /** A quarantine the ledger itself reads would charge every moved path a second time. */
    #[Test]
    public function aQuarantineInsideAWatchedEntryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('under the watched src');

        $this->ledger($this->root . '/src/leaks');
    }

    private function ledger(?string $quarantine = null, ?string $root = null): ProjectTreeLedger
    {
        return new ProjectTreeLedger($root ?? $this->root, $quarantine ?? $this->quarantine, 'var', 'vendor');
    }
}
