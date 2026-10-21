<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool;
use LTS\PHPQA\Pipeline\Runner\RepeatUntilSettled;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "the PHP CS Fixer cache lets a run pass a file an uncached run
 * fails" (#235). The fixer is not idempotent on every input: on a heredoc whose blank lines
 * carry whitespace, `heredoc_indentation` re-indents the heredoc on one pass and strips the
 * blank lines' whitespace on the next. A cached writable pass records its own output as
 * clean, so every later cached run skips the file: the read-only gate passes what CI, which
 * has no cache, fails, and the coding-standards phase's repeat-until-settled loop sees no
 * change on its second pass and stops.
 *
 * Each test runs the real lane over a probe project holding that heredoc, and judges the
 * outcome by the phar itself run uncached and read-only, as CI runs it.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpCsFixerToolCacheTest extends TestCase
{
    /** The probe file, relative to the probe project. */
    private const string FILE = 'src/Wrap.php';

    /** The probe project's checked path. */
    private const string SOURCE = 'src';

    /** The fixer's verdict on a file it would change. */
    private const string PENDING = 'pending fixes';

    /** The fixer's verdict on a file it would leave alone. */
    private const string CLEAN = 'clean';

    private TempDir $project;

    private string $library;

    protected function setUp(): void
    {
        $this->library = \dirname(__DIR__, 4);
        $this->project = TempDir::create('phpqa-fixer-cache');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        $this->project->mkdir('var/qa/cache');
        $this->project->write(self::FILE, $this->unsettledHeredoc());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theFixtureStillNeedsTwoPassesUncached(): void
    {
        self::assertSame(self::PENDING, $this->uncachedVerdict());
        $this->fixUncached();
        self::assertSame(self::PENDING, $this->uncachedVerdict(), 'PHP CS Fixer settled the heredoc in one pass, so this fixture no longer reproduces #235; find an input it still needs two passes for');
        $this->fixUncached();
        self::assertSame(self::CLEAN, $this->uncachedVerdict());
    }

    #[Test]
    public function aReadOnlyRunIgnoresACacheThatRecordsAnUnsettledFileAsClean(): void
    {
        $this->fixCached();
        $cached = $this->cachedVerdict();
        self::assertSame(self::CLEAN, $cached, 'precondition: the cache records the once-fixed heredoc as clean');

        $output = new BufferedOutput();
        $result = new PhpCsFixerTool()->run($this->context(true, $output));

        self::assertSame(self::PENDING, $this->uncachedVerdict(), 'precondition: an uncached run still has a fix to make');
        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, "the read-only lane passed a file an uncached run, as in CI, fails:\n" . $output->fetch());
    }

    #[Test]
    public function aSingleWritableRunLeavesNothingForAnUncachedCheck(): void
    {
        $output = new BufferedOutput();
        $result = new PhpCsFixerTool()->run($this->context(false, $output));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
        self::assertSame(self::CLEAN, $this->uncachedVerdict(), "after a writable fixer run, an uncached read-only check still has a fix to make:\n" . $this->project->read(self::FILE));
        self::assertSame(self::CLEAN, $this->cachedVerdict());
    }

    #[Test]
    public function aWritableCodingStandardsPhaseLeavesNothingForAnUncachedCheck(): void
    {
        $output = new BufferedOutput();
        new RepeatUntilSettled($output)->run(
            fn (): bool => new PhpCsFixerTool()->run($this->context(false, $output))->isSuccess(),
            $this->project->path,
            self::SOURCE,
        );

        self::assertSame(self::CLEAN, $this->uncachedVerdict(), "after the writable phase settled, an uncached read-only check still has a fix to make:\n" . $output->fetch());

        $readOnly = new BufferedOutput();
        self::assertSame(ToolOutcomeEnum::Passed, new PhpCsFixerTool()->run($this->context(true, $readOnly))->outcome, $readOnly->fetch());
    }

    #[Test]
    public function aCacheRecordingAnUnsettledFileDoesNotOutliveTheReadOnlyRunThatFailsIt(): void
    {
        $this->fixCached();
        self::assertSame(self::CLEAN, $this->cachedVerdict(), 'precondition: the cache records the once-fixed heredoc as clean');

        $readOnly = new BufferedOutput();
        self::assertSame(ToolOutcomeEnum::Failed, new PhpCsFixerTool()->run($this->context(true, $readOnly))->outcome, $readOnly->fetch());

        $writable = new BufferedOutput();
        new PhpCsFixerTool()->run($this->context(false, $writable));

        self::assertSame(self::CLEAN, $this->uncachedVerdict(), "the writable run the read-only failure asks for trusted the cache and left the fix unmade, so the read-only run fails for ever:\n" . $writable->fetch());
    }

    /**
     * A heredoc indented eight spaces, as `heredoc_indentation` would not leave it, whose
     * blank lines carry the eight spaces of the heredoc's indentation.
     */
    private function unsettledHeredoc(): string
    {
        $indent = str_repeat(' ', 8);

        return implode("\n", [
            "<?php\n",
            "declare(strict_types=1);\n",
            "namespace Probe;\n",
            'final class Wrap',
            '{',
            '    public function source(): string',
            '    {',
            $indent . "return <<<'PHP_WRAP'",
            $indent . '<?php',
            $indent,
            $indent . 'declare(strict_types=1);',
            $indent,
            $indent . 'return 1;',
            $indent . 'PHP_WRAP;',
            '    }',
            "}\n",
        ]);
    }

    private function context(bool $readOnly, BufferedOutput $output): ToolContext
    {
        return RealLaneContext::over($this->project->path, $readOnly, $output, self::SOURCE);
    }

    /** One writable pass that records what it leaves in the lane's cache file, as any cached fixer run does. */
    private function fixCached(): void
    {
        $this->assertFixerExit(0, $this->fixer('--cache-file=' . $this->cacheFile()));
    }

    private function fixUncached(): void
    {
        $this->assertFixerExit(0, $this->fixer('--using-cache=no'));
    }

    /** What a read-only run with no cache, as in CI, says of the probe project. */
    private function uncachedVerdict(): string
    {
        return $this->verdict($this->fixer('--using-cache=no', '--dry-run'));
    }

    /** What a read-only run trusting the lane's cache file says of the probe project. */
    private function cachedVerdict(): string
    {
        return $this->verdict($this->fixer('--cache-file=' . $this->cacheFile(), '--dry-run'));
    }

    private function verdict(Process $process): string
    {
        $this->assertFixerExit(0 === $process->getExitCode() ? 0 : 8, $process);

        return 0 === $process->getExitCode() ? self::CLEAN : self::PENDING;
    }

    private function cacheFile(): string
    {
        return $this->project->path . '/var/qa/cache/php_cs.cache';
    }

    private function fixer(string ...$options): Process
    {
        $process = new Process(
            [
                \PHP_BINARY,
                '-d',
                'display_errors=stderr',
                $this->library . '/vendor-phar/php-cs-fixer.phar',
                '--config=' . $this->library . '/configDefaults/generic/php_cs.php',
                '--allow-risky=yes',
                ...$options,
                ...['fix', self::SOURCE],
            ],
            $this->project->path,
            ['TMPDIR' => $this->project->path],
            null,
            600,
        );
        $process->run();

        return $process;
    }

    private function assertFixerExit(int $expected, Process $process): void
    {
        self::assertSame($expected, $process->getExitCode(), "PHP CS Fixer could not process the probe project:\n" . $process->getOutput() . $process->getErrorOutput());
    }
}
