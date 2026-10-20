<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanSetupCache;
use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanSetupKey;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The answers of dump-parameters and diagnose, kept under var/qa/cache/ against the key of
 * everything they depend on. A `-p` run reads them; any other run asks PHPStan and rewrites them.
 *
 * @internal
 */
#[CoversClass(PhpstanSetupCache::class)]
#[UsesClass(PhpstanSetupKey::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\InstalledPhpstanExtensions::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class PhpstanSetupCacheTest extends TestCase
{
    private const string CONFIG = 'qaConfig/phpstan.neon';

    private const string WRAPPER = 'var/qa/phpstan_logs/phpstan-parallel.neon';

    private const string PATH = 'src/Kernel.php';

    private const string ANSWER = '{"tmpDir":"/t/phpstan","sysGetTempDir":"/t"}';

    private const string DUMP_ENTRY = 'var/qa/cache/' . PhpstanSetupCache::DIR . '/dump-parameters.json';

    private const string FRESH = 'a fresh answer';

    private const string UNUSED = 'unused';

    private const string OUTER = 'outer';

    private ContextFactory $factory;

    private int $asked = 0;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(self::CONFIG, "parameters:\n    level: max\n");
        $this->factory->project->write(self::WRAPPER, \sprintf("includes:\n    - %s/%s\n", $this->factory->project->path, self::CONFIG));
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aMissAsksAndKeepsTheAnswer(): void
    {
        self::assertSame(self::ANSWER, $this->dump($this->pathRun()));

        self::assertSame(1, $this->asked);
        self::assertFileExists($this->entry());
    }

    #[Test]
    public function aPathRunWithTheSameKeyDoesNotAsk(): void
    {
        $this->dump($this->pathRun());

        self::assertSame(self::ANSWER, $this->dump($this->pathRun(), 'a second answer'));
        self::assertSame(1, $this->asked);
    }

    /**
     * A run that is not `-p` asks PHPStan whatever the cache holds and rewrites it, so a gap in
     * the key cannot outlive the next full run; there the seconds saved are noise.
     */
    #[Test]
    public function aFullRunAsksAndRewrites(): void
    {
        $this->dump($this->pathRun());

        self::assertSame(self::FRESH, $this->dump($this->fullRun(), self::FRESH));
        self::assertSame(2, $this->asked);
        self::assertSame(self::FRESH, $this->dump($this->pathRun(), self::UNUSED));
    }

    #[Test]
    public function aChangedKeyAsksAgain(): void
    {
        $this->dump($this->pathRun());
        $this->factory->project->write(self::CONFIG, "parameters:\n    level: 8\n");

        self::assertSame('after the edit', $this->dump($this->pathRun(), 'after the edit'));
        self::assertSame(2, $this->asked);
    }

    /** A failed probe is no answer, and an answer that is not there cannot be kept. */
    #[Test]
    public function aFailedProbeIsNotKept(): void
    {
        self::assertNull($this->dump($this->pathRun(), null));

        self::assertFileDoesNotExist($this->entry());
        self::assertSame(self::ANSWER, $this->dump($this->pathRun()));
        self::assertSame(2, $this->asked);
    }

    /** Where the key cannot see an input, PHPStan is asked and nothing is written. */
    #[Test]
    public function noKeyAsksAndKeepsNothing(): void
    {
        $this->factory->project->write(self::CONFIG, "parameters:\n    tmpDir: %env.CI_CACHE%\n");

        $this->dump($this->pathRun());
        $this->dump($this->pathRun());

        self::assertSame(2, $this->asked);
        self::assertFileDoesNotExist($this->entry());
    }

    /** A key that cannot be computed, here because composer.json does not parse, is said and then asked around. */
    #[Test]
    public function aKeyThatFailsIsReportedAndAskedAround(): void
    {
        $this->factory->project->write('vendor/phpstan/extension-installer/src/GeneratedConfig.php', 'x');
        $this->factory->project->write('composer.json', '{not json');

        self::assertSame(self::ANSWER, $this->dump($this->pathRun()));
        self::assertSame(1, $this->asked);
        self::assertStringContainsString('PHPStan: no cached setup answer (', $this->factory->output->fetch());
    }

    /** A cache that is corrupt, truncated or of another shape is a miss: PHPStan is asked and the entry rewritten. */
    #[Test]
    #[DataProvider('unusableEntries')]
    public function anUnusableEntryIsAMissAndIsRewritten(string $entry): void
    {
        $this->factory->project->write(self::DUMP_ENTRY, $entry);

        self::assertSame(self::ANSWER, $this->dump($this->pathRun()));
        self::assertSame(1, $this->asked);
        self::assertSame(self::ANSWER, $this->dump($this->pathRun(), self::UNUSED));
        self::assertSame(1, $this->asked);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableEntries(): iterable
    {
        yield 'not JSON' => ['{"key":'];
        yield 'empty' => [''];
        yield 'not an object' => ['"x"'];
        yield 'no output' => ['{"schema":1,"key":"k"}'];
        yield 'an output that is not a string' => ['{"schema":1,"key":"k","output":7}'];
    }

    /**
     * Two runs writing at once each write a file of their own and rename it into place, so the
     * entry is always one whole answer, never two interleaved, and no temporary file is left.
     */
    #[Test]
    public function concurrentWritersLeaveOneWholeEntry(): void
    {
        $context = $this->pathRun();
        $outer   = new PhpstanSetupCache()->dumpParameters($context, $this->config(), null, function () use ($context): string {
            ++$this->asked;
            new PhpstanSetupCache()->dumpParameters($context, $this->config(), null, function (): string {
                ++$this->asked;

                return 'inner';
            });

            return self::OUTER;
        });

        self::assertSame(self::OUTER, $outer);
        self::assertSame(['dump-parameters.json'], $this->factory->project->files('var/qa/cache/' . PhpstanSetupCache::DIR));
        self::assertSame(self::OUTER, $this->dump($this->pathRun(), self::UNUSED));
    }

    /** A temporary file a killed writer left behind is not the entry, and does not stop the next write. */
    #[Test]
    public function aLeftoverTemporaryFileIsIgnored(): void
    {
        $this->factory->project->write(self::DUMP_ENTRY . '.abc123.tmp', '{"schema":1,"key":"');

        self::assertSame(self::ANSWER, $this->dump($this->pathRun()));
        self::assertSame(self::ANSWER, $this->dump($this->pathRun(), self::UNUSED));
        self::assertSame(1, $this->asked);
    }

    /** The diagnose answer is kept apart from the dump's, against its own key. */
    #[Test]
    public function theDiagnoseAnswerIsKeptOnItsOwn(): void
    {
        $runtime = \Safe\json_encode(['binary' => \PHP_BINARY, 'ini' => false, 'scanned' => false]);
        $this->factory->processes->willSucceed($runtime)->willSucceed($runtime);
        $cache = new PhpstanSetupCache();
        $ask   = function (): string {
            ++$this->asked;

            return "Turbo extension: enabled (version 28c29b8)\n";
        };

        $cache->diagnose($this->pathRun(), $this->factory->project->path . '/' . self::WRAPPER, $ask);
        $second = $cache->diagnose($this->pathRun(), $this->factory->project->path . '/' . self::WRAPPER, $ask);

        self::assertSame("Turbo extension: enabled (version 28c29b8)\n", $second);
        self::assertSame(1, $this->asked);
        self::assertFileExists($this->factory->project->path . '/var/qa/cache/' . PhpstanSetupCache::DIR . '/diagnose.json');
    }

    private function dump(ToolContext $context, ?string $answer = self::ANSWER): ?string
    {
        return new PhpstanSetupCache()->dumpParameters($context, $this->config(), null, function () use ($answer): ?string {
            ++$this->asked;

            return $answer;
        });
    }

    private function config(): string
    {
        return $this->factory->project->path . '/' . self::CONFIG;
    }

    private function entry(): string
    {
        return $this->factory->project->path . '/' . self::DUMP_ENTRY;
    }

    private function pathRun(): ToolContext
    {
        return $this->factory->context($this->factory->builder(specifiedPath: self::PATH)->build());
    }

    private function fullRun(): ToolContext
    {
        return $this->factory->context($this->factory->builder()->build());
    }
}
