<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\ShellCheck;

use LTS\PHPQA\Pipeline\Lane\ShellCheck\DaemonOwnedFiles;
use LTS\PHPQA\Pipeline\Lane\ShellCheck\Dto\DaemonOwnedSplitDto;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DaemonOwnedFiles::class)]
#[UsesClass(DaemonOwnedSplitDto::class)]
#[Small]
final class DaemonOwnedFilesTest extends TestCase
{
    private const string STUB = '.claude/hooks/stop';

    private const string HAND_WRITTEN = '.claude/hooks/test-all-hooks.sh';

    private const string INSTALLED = '.claude/hooks-daemon/scripts/run.bash';

    private const string HAND_BODY = "#!/bin/bash\nunused=1\n";

    private const string SHEBANG = "#!/bin/bash\n";

    private const string SIBLING = '.claude/hooks-daemon-notes/x.bash';

    private const string DEEP = 'scripts/doc.bash';

    private const string STUB_BODY = self::SHEBANG . "#\n# DAEMON-OWNED FILE - do not edit. Deployed into your project by an installer and\n# refreshed on every upgrade, so local changes are discarded.\n#\nunused=1\n";

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-daemonowned');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aStubCarryingTheDaemonsOwnershipMarkerIsSkippedAndAHandWrittenNeighbourIsKept(): void
    {
        $this->project->write(self::STUB, self::STUB_BODY);
        $this->project->write(self::HAND_WRITTEN, self::HAND_BODY);

        $split = new DaemonOwnedFiles($this->project->path)->partition(self::STUB, self::HAND_WRITTEN);

        self::assertSame([self::HAND_WRITTEN], $split->kept);
        self::assertSame([self::STUB], $split->skipped);
    }

    #[Test]
    public function theInstallFolderIsSkippedWhateverItsFilesSay(): void
    {
        $this->project->write(self::INSTALLED, self::HAND_BODY);

        $split = new DaemonOwnedFiles($this->project->path)->partition(self::INSTALLED);

        self::assertSame([], $split->kept);
        self::assertSame([self::INSTALLED], $split->skipped);
    }

    #[Test]
    public function aSiblingFolderWhoseNameOnlyStartsLikeTheInstallFolderIsKept(): void
    {
        $this->project->write(self::SIBLING, self::HAND_BODY);

        $split = new DaemonOwnedFiles($this->project->path)->partition(self::SIBLING);

        self::assertSame([self::SIBLING], $split->kept);
        self::assertSame([], $split->skipped);
    }

    #[Test]
    public function theMarkerDeepInsideAFileDoesNotMakeItDaemonOwned(): void
    {
        $this->project->write(self::DEEP, self::SHEBANG . str_repeat("true\n", 30) . "# DAEMON-OWNED FILE - do not edit.\n");

        $split = new DaemonOwnedFiles($this->project->path)->partition(self::DEEP);

        self::assertSame([self::DEEP], $split->kept);
    }

    #[Test]
    public function aFileThatIsNotOnDiskIsKeptForTheCallerToJudge(): void
    {
        $split = new DaemonOwnedFiles($this->project->path)->partition('missing.bash');

        self::assertSame(['missing.bash'], $split->kept);
    }
}
