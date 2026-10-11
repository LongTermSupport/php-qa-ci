<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use LTS\PHPQA\VersionPins\SafeScanFilesDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SafeScanFilesDetector::class)]
#[Small]
final class SafeScanFilesDetectorTest extends TestCase
{
    private const string PHP_MAJOR_MINOR = '8.5';

    private const string PHP_82 = '8.2';

    private const string PHP_84 = '8.4';

    private const string FIXTURE_ROOT = __DIR__ . '/../../assets/versionPins/project/current';

    private const string ARRAY_84 = 'vendor/thecodingmachine/safe/generated/8.4/array.php';

    private const string ARRAY_82 = 'vendor/thecodingmachine/safe/generated/8.2/array.php';

    private const string EXEC_82 = 'vendor/thecodingmachine/safe/generated/8.2/exec.php';

    private const string EXEC_84 = 'vendor/thecodingmachine/safe/generated/8.4/exec.php';

    private const string ABSENT_DISPATCHER = 'vendor/thecodingmachine/safe/generated/8.4/nope.php';

    private SafeScanFilesDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new SafeScanFilesDetector();
    }

    #[Test]
    public function itPassesWhenEveryEntryIsTheFileSafeLoadsOnThatPhp(): void
    {
        self::assertSame([], $this->detector->check(self::FIXTURE_ROOT, self::PHP_MAJOR_MINOR, self::ARRAY_84, self::EXEC_82));
    }

    #[Test]
    public function itJudgesAgainstTheGivenPhpVersionNotTheListedOne(): void
    {
        // On 8.2 safe loads 8.2/array.php, so the 8.4 entry is the stale one there.
        self::assertSame([], $this->detector->check(self::FIXTURE_ROOT, self::PHP_82, self::ARRAY_82));
        self::assertSame(
            [$this->staleMessage(self::ARRAY_84, self::PHP_82, self::PHP_82, self::PHP_84, self::ARRAY_82)],
            $this->detector->check(self::FIXTURE_ROOT, self::PHP_82, self::ARRAY_84),
        );
    }

    #[Test]
    public function itJudgesTheEntriesAfterANonSafeEntry(): void
    {
        self::assertSame(
            [$this->staleArray82On85()],
            $this->detector->check(self::FIXTURE_ROOT, self::PHP_MAJOR_MINOR, 'src/bootstrap.php', self::ARRAY_82),
        );
    }

    #[Test]
    public function itJudgesTheEntriesAfterASafeEntryWhoseDispatcherIsAbsent(): void
    {
        self::assertSame(
            [$this->staleArray82On85()],
            $this->detector->check(self::FIXTURE_ROOT, self::PHP_MAJOR_MINOR, self::ABSENT_DISPATCHER, self::ARRAY_82),
        );
    }

    #[Test]
    public function itReportsEveryEntryWithNoBranchForThatPhpInOrder(): void
    {
        self::assertSame(
            [
                '"' . self::EXEC_82 . '": safe has no exec.php branch for PHP 8.6, so this file is never loaded on the PHP QA runs under; remove the entry',
                '"' . self::ARRAY_84 . '": safe has no array.php branch for PHP 8.6, so this file is never loaded on the PHP QA runs under; remove the entry',
            ],
            $this->detector->check(self::FIXTURE_ROOT, '8.6', self::EXEC_82, self::ARRAY_84),
        );
    }

    #[Test]
    public function itReportsEveryStaleEntryInOrder(): void
    {
        self::assertSame(
            [
                $this->staleArray82On85(),
                $this->staleMessage(self::EXEC_84, self::PHP_MAJOR_MINOR, self::PHP_82, self::PHP_84, self::EXEC_82),
            ],
            $this->detector->check(self::FIXTURE_ROOT, self::PHP_MAJOR_MINOR, self::ARRAY_82, self::EXEC_82, self::EXEC_84),
        );
    }

    #[Test]
    public function itReportsAnEntryNamingADirectorySafeDoesNotLoadWithTheExactReplacement(): void
    {
        self::assertSame(
            [
                '"' . self::ARRAY_82 . '": on PHP 8.5 safe loads generated/8.4/array.php, not the 8.2 one; '
                . 'replace the entry with "' . self::ARRAY_84 . '"',
            ],
            $this->detector->check(self::FIXTURE_ROOT, self::PHP_MAJOR_MINOR, self::ARRAY_82),
        );
    }

    #[Test]
    public function itReportsAnEntryWhoseDispatcherHasNoBranchForThatPhp(): void
    {
        $problems = $this->detector->check(self::FIXTURE_ROOT, '8.6', self::EXEC_82);

        self::assertSame(
            ['"' . self::EXEC_82 . '": safe has no exec.php branch for PHP 8.6, so this file is never loaded on the PHP QA runs under; remove the entry'],
            $problems,
        );
    }

    #[Test]
    public function itIgnoresEntriesThatAreNotSafeGeneratedFiles(): void
    {
        self::assertSame([], $this->detector->check(self::FIXTURE_ROOT, self::PHP_MAJOR_MINOR, 'vendor/some/other/file.php', 'src/bootstrap.php'));
    }

    #[Test]
    public function itIgnoresASafeEntryWhoseDispatcherIsAbsent(): void
    {
        self::assertSame([], $this->detector->check(self::FIXTURE_ROOT, self::PHP_MAJOR_MINOR, self::ABSENT_DISPATCHER));
    }

    #[Test]
    public function itMatchesTheDotInThePhpVersionLiterally(): void
    {
        $source = "<?php\nif (str_starts_with(PHP_VERSION, \"8x5.\")) {\n    require_once __DIR__ . '/9.9/array.php';\n}\n";

        self::assertNull($this->detector->loadedVersionDir($source, self::PHP_MAJOR_MINOR));
    }

    #[Test]
    public function itReadsTheLoadedDirectoryFromTheDispatcherSource(): void
    {
        $source = "<?php\nif (str_starts_with(PHP_VERSION, \"8.5.\")) {\n    require_once __DIR__ . '/8.4/array.php';\n}\n";

        self::assertSame(self::PHP_84, $this->detector->loadedVersionDir($source, self::PHP_MAJOR_MINOR));
        self::assertNull($this->detector->loadedVersionDir($source, '8.3'));
    }

    private function staleArray82On85(): string
    {
        return $this->staleMessage(self::ARRAY_82, self::PHP_MAJOR_MINOR, self::PHP_84, self::PHP_82, self::ARRAY_84);
    }

    private function staleMessage(string $entry, string $phpMajorMinor, string $loadedVersion, string $listedVersion, string $replacement): string
    {
        return '"' . $entry . '": on PHP ' . $phpMajorMinor . ' safe loads generated/' . $loadedVersion . '/' . basename($entry)
            . ', not the ' . $listedVersion . ' one; replace the entry with "' . $replacement . '"';
    }
}
