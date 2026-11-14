<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support;

use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Tests\Support\WorkflowFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
#[Small]
final class WorkflowFilesTest extends TestCase
{
    private TempDir $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('workflow-files');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    #[Test]
    public function aWorkflowIsListedBySortedPathAndNothingElseIs(): void
    {
        $this->dir->write('workflows/b.yml', "on: push\n");
        $this->dir->write('workflows/a.yml', "on: push\n");
        $this->dir->write('workflows/notes.md', 'not a workflow');
        $this->dir->write('workflows/nested/c.yml', "on: push\n");

        self::assertSame(
            [$this->dir->path . '/workflows/a.yml', $this->dir->path . '/workflows/b.yml'],
            WorkflowFiles::in($this->dir->path . '/workflows'),
        );
    }

    #[Test]
    public function aCompositeActionIsListedFromTheDirectoryOfActions(): void
    {
        $this->dir->write('actions/one/action.yml', "runs:\n  using: composite\n");
        $this->dir->write('actions/two/README.md', 'no action here');

        self::assertSame(
            [$this->dir->path . '/actions/one/action.yml'],
            WorkflowFiles::actionsIn($this->dir->path . '/actions'),
        );
    }

    #[Test]
    public function aDirectoryThatDoesNotExistHoldsNoFiles(): void
    {
        self::assertSame([], WorkflowFiles::in($this->dir->path . '/missing'));
        self::assertSame([], WorkflowFiles::actionsIn($this->dir->path . '/missing'));
    }
}
