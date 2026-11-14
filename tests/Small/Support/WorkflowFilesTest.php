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
    private const string WORKFLOW = "on: push\n";

    private const string ACTION = "runs:\n  using: composite\n";

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
        $this->dir->write('workflows/b.yml', self::WORKFLOW);
        $this->dir->write('workflows/a.yml', self::WORKFLOW);
        $this->dir->write('workflows/notes.md', 'not a workflow');
        $this->dir->write('workflows/nested/c.yml', self::WORKFLOW);

        self::assertSame(
            [$this->dir->path . '/workflows/a.yml', $this->dir->path . '/workflows/b.yml'],
            WorkflowFiles::in($this->dir->path . '/workflows'),
        );
    }

    #[Test]
    public function aCompositeActionIsListedFromTheDirectoryOfActions(): void
    {
        $this->dir->write('actions/one/action.yml', self::ACTION);
        $this->dir->write('actions/two/README.md', 'no action here');

        self::assertSame(
            [$this->dir->path . '/actions/one/action.yml'],
            WorkflowFiles::actionsIn($this->dir->path . '/actions'),
        );
    }

    #[Test]
    public function aWorkflowSavedWithTheYamlExtensionIsListedAsMuchAsOneSavedAsYml(): void
    {
        $this->dir->write('workflows/a.yml', self::WORKFLOW);
        $this->dir->write('workflows/extra.yaml', "on: pull_request\n");

        self::assertSame(
            [$this->dir->path . '/workflows/a.yml', $this->dir->path . '/workflows/extra.yaml'],
            WorkflowFiles::in($this->dir->path . '/workflows'),
        );
    }

    #[Test]
    public function aCompositeActionSavedAsActionYamlIsListedAsMuchAsActionYml(): void
    {
        $this->dir->write('actions/one/action.yml', self::ACTION);
        $this->dir->write('actions/two/action.yaml', self::ACTION);

        self::assertSame(
            [$this->dir->path . '/actions/one/action.yml', $this->dir->path . '/actions/two/action.yaml'],
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
