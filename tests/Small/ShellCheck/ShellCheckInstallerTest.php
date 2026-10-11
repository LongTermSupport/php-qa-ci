<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ShellCheck;

use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use LTS\PHPQA\ShellCheck\Dto\InstallDecisionDto;
use LTS\PHPQA\ShellCheck\InstallActionEnum;
use LTS\PHPQA\ShellCheck\InstallDecider;
use LTS\PHPQA\ShellCheck\ShellCheckInstaller;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The install mode, which runs on every `composer install` and never reaches the network: it is
 * silent when the committed binary and its pin are both there, and says on the error output how
 * to restore whichever is missing. The update mode fetches from GitHub and is not run here.
 *
 * @internal
 */
#[CoversClass(ShellCheckInstaller::class)]
#[UsesClass(InstallDecider::class)]
#[UsesClass(InstallDecisionDto::class)]
#[UsesClass(InstallActionEnum::class)]
#[UsesClass(ShellCheckBinary::class)]
#[Small]
final class ShellCheckInstallerTest extends TestCase
{
    private const string PINNED = 'v0.10.0';

    private TempDir $library;

    private BufferedOutput $output;

    private BufferedOutput $errors;

    protected function setUp(): void
    {
        $this->library = TempDir::create('phpqa-shellcheck-installer');
        $this->output  = new BufferedOutput();
        $this->errors  = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    public function aCommittedBinaryWithItsPinInstallsSilently(): void
    {
        $this->library->write(ShellCheckBinary::RELATIVE_PATH, 'binary');
        $this->library->write(ShellCheckBinary::VERSION_FILE, self::PINNED . "\n");

        self::assertSame(0, $this->installer()->run($this->library->path, ShellCheckInstaller::MODE_INSTALL));
        self::assertSame('', $this->output->fetch());
        self::assertSame('', $this->errors->fetch());
    }

    #[Test]
    public function aMissingBinaryFailsAndNamesTheCommandThatRestoresThePinnedOne(): void
    {
        $this->library->write(ShellCheckBinary::VERSION_FILE, self::PINNED . "\n");

        self::assertSame(1, $this->installer()->run($this->library->path, ShellCheckInstaller::MODE_INSTALL));
        self::assertSame('', $this->output->fetch());
        self::assertSame(
            "ERROR: the vendored ShellCheck is missing (no binary), but it is committed to the repository.\n"
            . \sprintf("Expected: %s (linux.x86_64)\n", ShellCheckBinary::path($this->library->path))
            . "This indicates a corrupted or incomplete checkout. Re-clone, or restore it with:\n"
            . '  ' . ShellCheckBinary::fetchCommand($this->library->path, self::PINNED) . "\n",
            $this->errors->fetch(),
        );
    }

    #[Test]
    public function aMissingPinFailsAndPointsAtTheUpdateThatRePinsIt(): void
    {
        $this->library->write(ShellCheckBinary::RELATIVE_PATH, 'binary');

        self::assertSame(1, $this->installer()->run($this->library->path, ShellCheckInstaller::MODE_INSTALL));
        self::assertSame('', $this->output->fetch());
        self::assertSame(
            "ERROR: the vendored ShellCheck is missing (no version pin recorded), but it is committed to the repository.\n"
            . \sprintf("Expected: %s (linux.x86_64)\n", ShellCheckBinary::path($this->library->path))
            . "This indicates a corrupted or incomplete checkout. Re-clone, or restore it with:\n"
            . "  scripts/tool-install.bash update   (re-pins to the newest release)\n",
            $this->errors->fetch(),
        );
    }

    private function installer(): ShellCheckInstaller
    {
        return new ShellCheckInstaller($this->output, $this->errors);
    }
}
