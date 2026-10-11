<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ShellCheck;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use LTS\PHPQA\ShellCheck\Dto\InstallDecisionDto;
use LTS\PHPQA\ShellCheck\InstallActionEnum;
use LTS\PHPQA\ShellCheck\InstallDecider;
use LTS\PHPQA\ShellCheck\ShellCheckInstaller;
use LTS\PHPQA\Tests\Support\TempDir;
use Phar;
use PharData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The install mode, which runs on every `composer install` and never reaches the network: it is
 * silent when the committed binary and its pin are both there, and says on the error output how
 * to restore whichever is missing. The update mode runs against canned responses in place of
 * GitHub: the release lookup, and an archive built here in the shape of the published asset.
 *
 * @internal
 */
#[CoversClass(ShellCheckInstaller::class)]
#[UsesClass(TemporaryDirectory::class)]
#[UsesClass(InstallDecider::class)]
#[UsesClass(InstallDecisionDto::class)]
#[UsesClass(InstallActionEnum::class)]
#[UsesClass(ShellCheckBinary::class)]
#[Small]
final class ShellCheckInstallerTest extends TestCase
{
    private const string PINNED = 'v0.10.0';

    private const string BINARY_BYTES = 'binary';

    private const string NOTE = 'Note: could not fetch ';

    private const string PIN_KEPT = "ShellCheck stays at v0.10.0 — could not reach the GitHub release API.\n";

    private const string NEWEST = 'v0.11.0';

    private const string TOKEN_VARIABLE = 'GITHUB_AUTH_TOKEN';

    private const string RELEASE_API = 'https://api.github.com/repos/koalaman/shellcheck/releases/latest';

    private const string NEWEST_ASSET = 'https://github.com/koalaman/shellcheck/releases/download/v0.11.0/shellcheck-v0.11.0.linux.x86_64.tar.gz';

    private TempDir $library;

    private BufferedOutput $output;

    private BufferedOutput $errors;

    /** @var list<string> every URL the installer asked for, in order */
    private array $requests = [];

    /** @var list<list<string>> the headers sent with each request, in order */
    private array $headers = [];

    /** @var list<string> every URL asked for that the test gave no answer to */
    private array $unanswered = [];

    protected function setUp(): void
    {
        $this->library = TempDir::create('phpqa-shellcheck-installer');
        $this->output  = new BufferedOutput();
        $this->errors  = new BufferedOutput();
    }

    protected function assertPostConditions(): void
    {
        self::assertSame([], $this->unanswered, 'the installer asked for a URL the test did not expect');
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    public function aCommittedBinaryWithItsPinInstallsSilently(): void
    {
        $this->library->write(ShellCheckBinary::RELATIVE_PATH, self::BINARY_BYTES);
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
            . $this->brokenCheckout()
            . '  ' . ShellCheckBinary::fetchCommand($this->library->path, self::PINNED) . "\n",
            $this->errors->fetch(),
        );
    }

    #[Test]
    public function aMissingPinFailsAndPointsAtTheUpdateThatRePinsIt(): void
    {
        $this->library->write(ShellCheckBinary::RELATIVE_PATH, self::BINARY_BYTES);

        self::assertSame(1, $this->installer()->run($this->library->path, ShellCheckInstaller::MODE_INSTALL));
        self::assertSame('', $this->output->fetch());
        self::assertSame(
            "ERROR: the vendored ShellCheck is missing (no version pin recorded), but it is committed to the repository.\n"
            . $this->brokenCheckout()
            . "  scripts/tool-install.bash update   (re-pins to the newest release)\n",
            $this->errors->fetch(),
        );
    }

    #[Test]
    public function anUpdateOutsideAMaintainerBuildFetchesNothing(): void
    {
        $this->pin();

        self::assertSame(0, $this->installer([], maintainer: false)->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame("ShellCheck left as committed — skipping update (not a maintainer build).\n", $this->output->fetch());
        self::assertSame('', $this->errors->fetch());
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function anUpdateAlreadyAtTheNewestReleaseSaysSoAndDownloadsNothing(): void
    {
        $this->pin();

        $installer = $this->installer([self::RELEASE_API => $this->release(self::PINNED)]);

        self::assertSame(0, $installer->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame("ShellCheck is already at the newest release (v0.10.0).\n", $this->output->fetch());
        self::assertSame('', $this->errors->fetch());
        self::assertSame([self::RELEASE_API], $this->requests);
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('tokens')]
    public function eachRequestNamesItsClientAndCarriesTheTokenWhenOneIsSet(false|string $token, array $expected): void
    {
        $this->pin();
        $original = getenv(self::TOKEN_VARIABLE);
        \Safe\putenv(false === $token ? self::TOKEN_VARIABLE : self::TOKEN_VARIABLE . '=' . $token);

        try {
            $this->installer([self::RELEASE_API => $this->release(self::PINNED)])->run($this->library->path, ShellCheckInstaller::MODE_UPDATE);
        } finally {
            \Safe\putenv(false === $original ? self::TOKEN_VARIABLE : self::TOKEN_VARIABLE . '=' . $original);
        }

        self::assertSame([$expected], $this->headers);
    }

    /** @return iterable<string, array{false|string, list<string>}> */
    public static function tokens(): iterable
    {
        $anonymous = ['User-Agent: php-qa-ci', 'Accept: application/vnd.github+json'];

        yield 'no token' => [false, $anonymous];
        yield 'an empty token' => ['', $anonymous];
        yield 'a token' => ['a-token', [...$anonymous, 'Authorization: Bearer a-token']];
    }

    #[Test]
    public function anUnreachableReleaseApiLeavesThePinAndPrintsTheCause(): void
    {
        $this->pin();

        $installer = $this->installer([self::RELEASE_API => new RuntimeException('HTTP 403 rate limited')]);

        self::assertSame(0, $installer->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame(self::PIN_KEPT, $this->output->fetch());
        self::assertSame(self::NOTE . self::RELEASE_API . ": HTTP 403 rate limited\n", $this->errors->fetch());
    }

    #[Test]
    public function aReleaseResponseThatIsNotJsonIsNotedAndLeavesThePin(): void
    {
        $this->pin();

        self::assertSame(0, $this->installer([self::RELEASE_API => 'not json'])->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame(self::PIN_KEPT, $this->output->fetch());
        self::assertSame("Note: the GitHub release API returned something unreadable: Syntax error\n", $this->errors->fetch());
    }

    #[Test]
    #[DataProvider('releasesWithNoUsableTag')]
    public function aReleaseWithNoUsableTagLeavesThePin(string $response): void
    {
        $this->pin();

        self::assertSame(0, $this->installer([self::RELEASE_API => $response])->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame(self::PIN_KEPT, $this->output->fetch());
        self::assertSame('', $this->errors->fetch());
    }

    /** @return iterable<string, array{string}> */
    public static function releasesWithNoUsableTag(): iterable
    {
        yield 'not an object' => ['"v0.11.0"'];
        yield 'no tag' => ['{"name":"v0.11.0"}'];
        yield 'an empty tag' => ['{"tag_name":""}'];
        yield 'a tag that is not a string' => ['{"tag_name":11}'];
    }

    #[Test]
    public function aNewerReleaseIsUnpackedIntoTheLibraryAndPinned(): void
    {
        $this->library->write(ShellCheckBinary::VERSION_FILE, self::PINNED . "\n");
        $stagedBefore = $this->stagingDirectories();

        $installer = $this->installer([
            self::RELEASE_API   => $this->release(self::NEWEST),
            self::NEWEST_ASSET  => $this->releaseArchive(self::NEWEST, 'the newest build'),
        ]);

        self::assertSame(0, $installer->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame(
            "Updating ShellCheck v0.10.0 -> v0.11.0...\n"
            . "IMPORTANT: ShellCheck is tracked in git. Commit vendor-bin/shellcheck and vendor-bin/shellcheck.version.\n",
            $this->output->fetch(),
        );
        self::assertSame('', $this->errors->fetch());
        self::assertSame([self::RELEASE_API, self::NEWEST_ASSET], $this->requests);

        $binary = ShellCheckBinary::path($this->library->path);
        self::assertSame('the newest build', \Safe\file_get_contents($binary));
        self::assertSame(0o755, \Safe\fileperms($binary) & 0o777);
        self::assertSame(self::NEWEST, ShellCheckBinary::pinnedVersion($this->library->path));
        self::assertSame(self::NEWEST . "\n", $this->library->read(ShellCheckBinary::VERSION_FILE));
        self::assertSame($stagedBefore, $this->stagingDirectories(), 'the staging directory is removed');
    }

    #[Test]
    public function aDownloadThatFailsReportsTheCauseAndKeepsThePin(): void
    {
        $this->pin();
        $stagedBefore = $this->stagingDirectories();

        $installer = $this->installer([
            self::RELEASE_API  => $this->release(self::NEWEST),
            self::NEWEST_ASSET => new RuntimeException('HTTP 404'),
        ]);

        self::assertSame(1, $installer->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame("Updating ShellCheck v0.10.0 -> v0.11.0...\n", $this->output->fetch());
        self::assertSame(
            self::NOTE . self::NEWEST_ASSET . ": HTTP 404\n"
            . 'ERROR: could not install ShellCheck v0.11.0: download failed: ' . self::NEWEST_ASSET . "\n",
            $this->errors->fetch(),
        );
        self::assertSame(self::PINNED, ShellCheckBinary::pinnedVersion($this->library->path));
        self::assertSame(self::BINARY_BYTES, \Safe\file_get_contents(ShellCheckBinary::path($this->library->path)));
        self::assertSame($stagedBefore, $this->stagingDirectories(), 'the staging directory is removed');
    }

    #[Test]
    public function anUpdateWithNoPinAndNoReleaseApiFailsAndPointsAtTheUpdate(): void
    {
        $installer = $this->installer([self::RELEASE_API => new RuntimeException('offline')]);

        self::assertSame(1, $installer->run($this->library->path, ShellCheckInstaller::MODE_UPDATE));
        self::assertSame('', $this->output->fetch());
        self::assertSame(
            self::NOTE . self::RELEASE_API . ": offline\n"
            . "ERROR: there is no version pin and the GitHub release API could not be reached, so there is no version to fetch.\n"
            . $this->brokenCheckout()
            . "  scripts/tool-install.bash update   (re-pins to the newest release)\n",
            $this->errors->fetch(),
        );
    }

    /**
     * The installer, its requests answered from $responses by URL: a string is the body, a
     * Throwable is the failure. A URL with no answer fails too, and fails the test after it: the
     * installer catches every failed request, so the test cannot be failed from inside one.
     *
     * @param array<string, string|Throwable> $responses
     */
    private function installer(array $responses = [], bool $maintainer = true): ShellCheckInstaller
    {
        return new ShellCheckInstaller(
            $this->output,
            $this->errors,
            function (string $url, string ...$headers) use ($responses): string {
                $this->requests[] = $url;
                $this->headers[]  = array_values($headers);
                if (!\array_key_exists($url, $responses)) {
                    $this->unanswered[] = $url;

                    throw new RuntimeException('unexpected request: ' . $url);
                }

                $response = $responses[$url];

                return \is_string($response) ? $response : throw $response;
            },
            $maintainer,
        );
    }

    /** The lines that follow a broken checkout's error: where the binary belongs, and the advice. */
    private function brokenCheckout(): string
    {
        return \sprintf("Expected: %s (linux.x86_64)\n", ShellCheckBinary::path($this->library->path))
            . "This indicates a corrupted or incomplete checkout. Re-clone, or restore it with:\n";
    }

    /** A release lookup's response naming $tag. */
    private function release(string $tag): string
    {
        return \Safe\json_encode(['tag_name' => $tag]);
    }

    private function pin(): void
    {
        $this->library->write(ShellCheckBinary::RELATIVE_PATH, self::BINARY_BYTES);
        $this->library->write(ShellCheckBinary::VERSION_FILE, self::PINNED . "\n");
    }

    /** The bytes of a `.tar.gz` laid out as the published release asset is. */
    private function releaseArchive(string $version, string $binary): string
    {
        $build = TemporaryDirectory::create('phpqa-shellcheck-asset');

        try {
            $tar = new PharData($build->path . '/asset.tar');
            $tar->addFromString(\sprintf('shellcheck-%s/shellcheck', $version), $binary);
            $tar->compress(Phar::GZ);

            return \Safe\file_get_contents($build->path . '/asset.tar.gz');
        } finally {
            $build->remove();
        }
    }

    /** @return list<string> */
    private function stagingDirectories(): array
    {
        return array_values(array_filter(\Safe\glob(sys_get_temp_dir() . '/phpqa-shellcheck*'), is_string(...)));
    }
}
