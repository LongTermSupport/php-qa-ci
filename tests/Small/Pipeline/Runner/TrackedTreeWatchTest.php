<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Runner\TrackedTreeWatch;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Tests\Support\ScriptedTrackedTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TrackedTreeWatch::class)]
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
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(\LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboManifest::class)]
#[Small]
final class TrackedTreeWatchTest extends TestCase
{
    private const string FILE = 'config/reference.php';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aFileAWriteChangedSinceTheLastStepIsNamed(): void
    {
        $tree  = new ScriptedTrackedTree();
        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->readOnlyContext());

        self::assertSame([], $watch->changedBy());

        $tree->touch(self::FILE, 'one');
        self::assertSame([self::FILE], $watch->changedBy());
        self::assertSame([], $watch->changedBy(), 'a change is blamed on the step that made it, once');

        $tree->touch(self::FILE, 'two');
        self::assertSame([self::FILE], $watch->changedBy(), 'rewritten again by a later step');
    }

    #[Test]
    public function aFileDirtyBeforeTheRunIsTheBaselineAndNotBlamedOnIt(): void
    {
        $tree = new ScriptedTrackedTree();
        $tree->touch(self::FILE, 'already dirty');

        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->readOnlyContext());

        self::assertSame([], $watch->changedBy());

        $tree->touch(self::FILE, 'dirtier');
        self::assertSame([self::FILE], $watch->changedBy());
    }

    #[Test]
    public function aFileAStepRestoredToCleanIsAChangeToo(): void
    {
        $tree = new ScriptedTrackedTree();
        $tree->touch(self::FILE, 'already dirty');

        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->readOnlyContext());

        $tree->clean(self::FILE);

        self::assertSame([self::FILE], $watch->changedBy());
    }

    #[Test]
    public function changedFilesAreListedInOrder(): void
    {
        $tree  = new ScriptedTrackedTree();
        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->readOnlyContext());

        $tree->touch('src/b.php');
        $tree->touch('src/a.php');

        self::assertSame(['src/a.php', 'src/b.php'], $watch->changedBy());
    }

    #[Test]
    public function aWritableRunIsNeverWatched(): void
    {
        $tree  = new ScriptedTrackedTree();
        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build()));

        $tree->touch(self::FILE);

        self::assertSame([], $watch->changedBy());
        self::assertSame(0, $tree->asked);
    }

    #[Test]
    public function aRebaseTakesWhatChangedSinceAsTheNewBaseline(): void
    {
        $tree  = new ScriptedTrackedTree();
        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->readOnlyContext());

        $tree->touch(self::FILE, 'written by the run this one waited for');
        $watch->rebase();

        self::assertSame([], $watch->changedBy(), 'not this run\'s change');

        $tree->touch(self::FILE, 'written by a step of this run');
        self::assertSame([self::FILE], $watch->changedBy());
    }

    #[Test]
    public function aRebaseOfAnIdleWatchReadsNothing(): void
    {
        $tree  = new ScriptedTrackedTree();
        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->factory->context($this->factory->builder(readOnly: false, aggregate: false)->build()));

        $watch->rebase();

        self::assertSame(0, $tree->asked);
    }

    #[Test]
    public function anAgentModeRunIsNeverWatched(): void
    {
        $tree  = new ScriptedTrackedTree();
        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->factory->context($this->factory->builder(readOnly: true, aggregate: true, agentMode: true)->build()));

        $tree->touch(self::FILE);

        self::assertSame([], $watch->changedBy());
        self::assertSame(0, $tree->asked, 'agent mode has its own exit-code contract, so the tree is never read');
    }

    #[Test]
    public function aTreeGitCannotReadIsSaidOnceAndThenIgnored(): void
    {
        $tree  = new ScriptedTrackedTree(answers: false);
        $watch = new TrackedTreeWatch($tree, $this->factory->output);
        $watch->begin($this->readOnlyContext());

        self::assertSame([], $watch->changedBy());
        self::assertSame([], $watch->changedBy());
        self::assertSame(1, $tree->asked, 'the tree is not asked again once git has said it cannot answer');
        self::assertSame(1, substr_count($this->factory->output->fetch(), 'could not read the tracked files'));
    }

    #[Test]
    public function aRunWithNoTreeIsNeverWatched(): void
    {
        $watch = new TrackedTreeWatch(null, $this->factory->output);
        $watch->begin($this->readOnlyContext());

        self::assertSame([], $watch->changedBy());
    }

    private function readOnlyContext(): \LTS\PHPQA\Pipeline\Tool\ToolContext
    {
        return $this->factory->context($this->factory->builder(readOnly: true, aggregate: true)->build());
    }
}
