<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use Closure;
use LTS\PHPQA\Pipeline\Runner\RepeatUntilSettled;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversClass(RepeatUntilSettled::class)]
#[Small]
final class RepeatUntilSettledTest extends TestCase
{
    private const string SOURCE = 'src/Thing.php';

    private const string SRC = 'src';

    private TempDir $project;

    private BufferedOutput $output;

    private int $passes = 0;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-settle');
        $this->project->write(self::SOURCE, "<?php\n");

        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aPassThatChangesNothingRunsOnce(): void
    {
        $this->settle(fn (): bool => $this->pass(), self::SRC);

        self::assertSame(1, $this->passes);
        self::assertSame('', $this->output->fetch());
    }

    #[Test]
    public function aPassThatChangesAFileRunsAgainUntilOneChangesNothing(): void
    {
        $this->settle(fn (): bool => $this->pass(2 > $this->passes ? self::SOURCE : null), self::SRC);

        self::assertSame(3, $this->passes);
        $printed = $this->output->fetch();
        self::assertStringContainsString('1 file(s) changed on pass 1; running the phase again', $printed);
        self::assertStringContainsString('(pass 2 of at most 3)', $printed);
        self::assertStringContainsString('(pass 3 of at most 3)', $printed);
        self::assertStringNotContainsString('WARNING', $printed);
    }

    #[Test]
    public function aPhaseThatNeverSettlesStopsAtTheLimitAndNamesWhatStillChanged(): void
    {
        $this->settle(fn (): bool => $this->pass(self::SOURCE), self::SRC);

        self::assertSame(RepeatUntilSettled::MAX_PASSES, $this->passes);
        $printed = $this->output->fetch();
        self::assertStringContainsString('WARNING: the phase still changed 1 file(s) on pass 3 of 3', $printed);
        self::assertStringContainsString($this->project->path . '/' . self::SOURCE, $printed);
    }

    #[Test]
    public function aFailedPassEndsTheLoopEvenWhenItChangedFiles(): void
    {
        $this->settle(function (): bool {
            $this->pass(self::SOURCE);

            return false;
        }, self::SRC);

        self::assertSame(1, $this->passes);
        self::assertSame('', $this->output->fetch());
    }

    #[Test]
    public function aFileCreatedOrDeletedIsAChange(): void
    {
        $this->settle(function (): bool {
            $this->pass();
            if (1 === $this->passes) {
                $this->project->write('src/New.php', "<?php\n");
            }

            if (2 === $this->passes) {
                \Safe\unlink($this->project->path . '/' . self::SOURCE);
            }

            return true;
        }, self::SRC);

        self::assertSame(3, $this->passes);
    }

    #[Test]
    public function onlyTheGivenPathsAreWatchedAndAnAbsolutePathIsAccepted(): void
    {
        $this->project->write('docs/README.md', "x\n");
        $this->settle(function (): bool {
            $this->pass();
            $this->project->write('docs/README.md', "x\n" . $this->passes);

            return true;
        }, $this->project->path . '/' . self::SOURCE, 'missing');

        self::assertSame(1, $this->passes, 'a change outside the watched paths is not the phase unsettling');
    }

    #[Test]
    public function aSingleFileIsWatched(): void
    {
        $this->settle(fn (): bool => $this->pass(0 === $this->passes ? self::SOURCE : null), self::SOURCE);

        self::assertSame(2, $this->passes);
    }

    /** @param Closure(): bool $pass */
    private function settle(Closure $pass, string ...$paths): void
    {
        new RepeatUntilSettled($this->output)->run($pass, $this->project->path, ...$paths);
    }

    /** One pass, rewriting $changes when given; the content differs on every pass. */
    private function pass(?string $changes = null): bool
    {
        ++$this->passes;
        if (null !== $changes) {
            $this->project->write($changes, "<?php\n// pass " . $this->passes . "\n");
        }

        return true;
    }
}
