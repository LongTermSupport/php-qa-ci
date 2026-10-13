<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support;

use LTS\PHPQA\Tests\Support\GitFilesCopy;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A copy of a checkout that holds what git sees in it: the tracked files and
 * the untracked ones that are not ignored. A `cp -r` of the working tree also
 * copies what is ignored there, every agent worktree and its vendor/
 * included, and any link it meets (#116).
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class GitFilesCopyTest extends TestCase
{
    private const string SCRIPT = 'bin/run.bash';

    private GitSandbox $sandbox;

    private TempDir $target;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create([
            '.gitignore'  => "untracked/\nvendor/\n",
            'README.md'   => 'readme',
            self::SCRIPT  => "#!/usr/bin/env bash\n",
            'src/Deep/A.php' => '<?php',
        ]);
        $this->target = TempDir::create('phpqa-git-files-copy');
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
        $this->target->remove();
    }

    #[Test]
    public function trackedAndUntrackedFilesAreCopiedAndIgnoredOnesAreNot(): void
    {
        $work = $this->sandbox->work;
        \Safe\file_put_contents($work . '/NEW.md', 'not yet added');
        \Safe\mkdir($work . '/untracked/worktrees/other/vendor', 0o700, true);
        \Safe\file_put_contents($work . '/untracked/worktrees/other/vendor/big.php', 'x');
        \Safe\mkdir($work . '/vendor');
        \Safe\file_put_contents($work . '/vendor/autoload.php', 'x');

        GitFilesCopy::copy($work, $this->target->path . '/copy');

        $copy = $this->target->path . '/copy';
        self::assertSame('readme', \Safe\file_get_contents($copy . '/README.md'));
        self::assertSame('<?php', \Safe\file_get_contents($copy . '/src/Deep/A.php'));
        self::assertSame('not yet added', \Safe\file_get_contents($copy . '/NEW.md'));
        self::assertDirectoryDoesNotExist($copy . '/untracked');
        self::assertDirectoryDoesNotExist($copy . '/vendor');
        self::assertDirectoryDoesNotExist($copy . '/.git');
    }

    #[Test]
    public function anExecutableStaysExecutable(): void
    {
        \Safe\chmod($this->sandbox->work . '/' . self::SCRIPT, 0o755);

        GitFilesCopy::copy($this->sandbox->work, $this->target->path . '/copy');

        self::assertTrue(is_executable($this->target->path . '/copy/' . self::SCRIPT));
    }

    #[Test]
    public function aLinkIsCopiedAsALinkAndATrackedFileDeletedFromTheTreeIsSkipped(): void
    {
        $work = $this->sandbox->work;
        \Safe\symlink('src', $work . '/linked');
        \Safe\unlink($work . '/README.md');

        GitFilesCopy::copy($work, $this->target->path . '/copy');

        $copy = $this->target->path . '/copy';
        self::assertTrue(is_link($copy . '/linked'));
        self::assertSame('src', \Safe\readlink($copy . '/linked'));
        self::assertFileDoesNotExist($copy . '/README.md');
    }
}
