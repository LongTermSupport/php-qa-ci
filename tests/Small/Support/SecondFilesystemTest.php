<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support;

use LTS\PHPQA\Tests\Support\SecondFilesystem;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/**
 * The atomic-replace tests need a directory on another filesystem from the system temp directory
 * (issue #251). Where there is none they are skipped, saying why: before, they errored in setUp
 * where /dev/shm could not be made, made a /dev/shm directory where it could, and failed their
 * device check where TMPDIR was itself on /dev/shm.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class SecondFilesystemTest extends TestCase
{
    private const string PREFIX = 'phpqa-second-filesystem-test';

    private TempDir $scratch;

    protected function setUp(): void
    {
        $this->scratch = TempDir::create('phpqa-second-filesystem');
    }

    protected function tearDown(): void
    {
        $this->scratch->remove();
    }

    #[Test]
    public function aCandidateThatCannotBeMadeSkipsTheTestInsteadOfErroring(): void
    {
        $notADirectory = $this->scratch->write('dev', 'a file, as /dev is not a directory /dev/shm can be made in');

        $this->expectSkip($notADirectory . '/shm', 'is not a directory');
    }

    #[Test]
    public function aCandidateThatIsAbsentSkipsTheTestAndIsNotMade(): void
    {
        $absent = $this->scratch->path . '/shm';

        try {
            $this->expectSkip($absent, 'is not a directory');
        } finally {
            self::assertDirectoryDoesNotExist($absent, 'the test does not make the second filesystem it was looking for');
        }
    }

    #[Test]
    public function aCandidateOnTheSystemTempDirectorysFilesystemSkipsTheTest(): void
    {
        $this->expectSkip($this->scratch->mkdir('shm'), 'on one filesystem');
    }

    private function expectSkip(string $candidate, string $reason): void
    {
        try {
            $made = SecondFilesystem::directory(self::PREFIX, $candidate);
        } catch (SkippedWithMessageException $skippedWithMessageException) {
            self::assertStringContainsString($reason, $skippedWithMessageException->getMessage());
            self::assertStringContainsString($candidate, $skippedWithMessageException->getMessage(), 'the reason names the directory');

            return;
        }

        $made->remove();
        self::fail('a test went on without a second filesystem, in ' . $made->path);
    }
}
