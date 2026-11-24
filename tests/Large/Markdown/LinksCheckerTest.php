<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Markdown;

use Exception;
use LTS\PHPQA\Markdown\LinksChecker;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Class LinksCheckerTest.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(LinksChecker::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Filesystem\UnresolvablePath::class)]
#[\PHPUnit\Framework\Attributes\Large]
final class LinksCheckerTest extends TestCase
{
    private const string GH_TOKEN_VAR = 'GH_TOKEN';

    private const string GITHUB_TOKEN_VAR = 'GITHUB_TOKEN';

    private const string README = '/README.md';

    private const string README_FILE = 'README.md';

    /** @var list<TempDir> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $dir->remove();
        }
    }

    /**
     * @throws Exception
     */
    public function testInvalidProject(): void
    {
        $pathToProject    = __DIR__ . '/../../assets/linksChecker/projectWithBrokenLinks';
        $expectedExitCode = 1;
        $expectedOutput   = '
/docs/linksCheckerTest.md
-------------------------

Bad link for "incorrect link" to "./../nothere.md"

/README.md
----------

Bad link for "incorrect link" to "./foo.md"
';
        self::assertResult($pathToProject, $expectedExitCode, $expectedOutput);
    }

    /**
     * @throws Exception
     */
    public function testMainNoReadmeFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('You have no README.md file in your project');
        LinksChecker::main(__DIR__ . '/../../assets/linksChecker/projectNoReadme');
    }

    /**
     * A root README.md that is a dangling symlink still fails the check, naming the dangling
     * symlink as the cause rather than claiming there is no README.md (#241).
     *
     * @throws Exception
     */
    public function testADanglingRootReadmeFailsNamingTheSymlink(): void
    {
        $dir              = TempDir::create('linksCheckerDanglingReadme');
        $this->tempDirs[] = $dir;
        \Safe\symlink('missing-target.md', $dir->path . self::README);

        try {
            LinksChecker::main($dir->path);
            self::fail('A dangling root README.md did not fail the check');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('README.md is a symlink whose target does not exist', $runtimeException->getMessage());
            self::assertStringNotContainsString('You have no README.md', $runtimeException->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function testValidNoDocsFolder(): void
    {
        $pathToProject    = __DIR__ . '/../../assets/linksChecker/projectWithReadmeNoDocsFolder';
        $expectedExitCode = 0;
        $expectedOutput   = '';
        self::assertResult($pathToProject, $expectedExitCode, $expectedOutput);
    }

    public function testItHandlesNonFileLinks(): void
    {
        // Hermetic HTTP checking: a local `php -S` server with a status-code
        // router replaces the old httpstat.us dependency, whose outages used
        // to fail (or skip) this test. The real network code path
        // (get_headers with a stream context) is still fully exercised.
        [$serverProcess, $baseUrl] = $this->startLocalStatusServer();

        try {
            $pathToProject = $this->createProjectWithNonFileLinks($baseUrl);

            $expectedExitCode = 1;
            $expectedOutput   = '
/README.md
----------

Bad link for "invalid link" to "' . $baseUrl . '/404"
result: HTTP status: 404
';
            self::assertResult($pathToProject, $expectedExitCode, $expectedOutput);
        } finally {
            proc_terminate($serverProcess);
            \Safe\proc_close($serverProcess);
        }
    }

    /**
     * Only a link the server says is gone (404, 410) fails the check. A server error, a refusal
     * to be checked (401, 403, 429) and a redirect are warnings: the page may well be fine, or
     * the link wants a new address, and a host being down must not stop a release.
     *
     * @throws Exception
     */
    public function testOnlyAGoneLinkFailsAndEverythingElseWarns(): void
    {
        [$serverProcess, $baseUrl] = $this->startLocalStatusServer();

        try {
            $dir              = TempDir::create('linksCheckerWarnings');
            $this->tempDirs[] = $dir;
            $dir->write(
                self::README_FILE,
                '[gone](' . $baseUrl . "/410)\n\n"
                . '[down](' . $baseUrl . "/502)\n\n"
                . '[busy](' . $baseUrl . "/503)\n\n"
                . '[private](' . $baseUrl . "/401)\n\n"
                . '[refused](' . $baseUrl . "/403)\n\n"
                . '[throttled](' . $baseUrl . "/429)\n\n"
                . '[moved](' . $baseUrl . "/301)\n\n"
                . '[found](' . $baseUrl . "/302)\n",
            );

            $note     = ' is not treated as a broken link';
            $expected = '
/README.md
----------

Bad link for "gone" to "' . $baseUrl . '/410"
result: HTTP status: 410

Warning for "down" to "' . $baseUrl . '/502"
result: HTTP status: 502, a server error' . $note . '

Warning for "busy" to "' . $baseUrl . '/503"
result: HTTP status: 503, a server error' . $note . '

Warning for "private" to "' . $baseUrl . '/401"
result: HTTP status: 401, the server would not say whether the page exists, so it' . $note . '

Warning for "refused" to "' . $baseUrl . '/403"
result: HTTP status: 403, the server would not say whether the page exists, so it' . $note . '

Warning for "throttled" to "' . $baseUrl . '/429"
result: HTTP status: 429, the server would not say whether the page exists, so it' . $note . '

Warning for "moved" to "' . $baseUrl . '/301"
result: HTTP status: 301, redirected to /200; update the link to the address it ends on

Warning for "found" to "' . $baseUrl . '/302"
result: HTTP status: 302, redirected to /200; update the link to the address it ends on
';
            self::assertResult($dir->path, 1, $expected);
        } finally {
            proc_terminate($serverProcess);
            \Safe\proc_close($serverProcess);
        }
    }

    /**
     * Warnings alone never fail the check, so a run is green when the only findings are a host
     * that is down and a link that has moved.
     *
     * @throws Exception
     */
    public function testWarningsAloneLeaveTheCheckPassing(): void
    {
        [$serverProcess, $baseUrl] = $this->startLocalStatusServer();

        try {
            $dir              = TempDir::create('linksCheckerWarningsOnly');
            $this->tempDirs[] = $dir;
            $dir->write(
                self::README_FILE,
                '[down](' . $baseUrl . "/502)\n\n[moved](" . $baseUrl . "/301)\n",
            );

            \Safe\ob_start();
            $exit   = LinksChecker::main($dir->path);
            $output = \Safe\ob_get_clean();

            self::assertSame(0, $exit);
            self::assertStringContainsString('Warning for "down"', $output);
            self::assertStringContainsString('Warning for "moved"', $output);
            self::assertStringNotContainsString('Bad link', $output);
        } finally {
            proc_terminate($serverProcess);
            \Safe\proc_close($serverProcess);
        }
    }

    /**
     * A host that cannot be reached at all is a warning too: the network, not the page, may be
     * what is down.
     *
     * @throws Exception
     */
    public function testAnUnreachableHostWarnsAndDoesNotFail(): void
    {
        $probe = \Safe\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name  = \Safe\stream_socket_get_name($probe, false);
        $port  = (int)substr($name, (int)strrpos($name, ':') + 1);
        \Safe\fclose($probe);

        $dir              = TempDir::create('linksCheckerUnreachable');
        $this->tempDirs[] = $dir;
        $dir->write(self::README_FILE, '[nobody home](http://127.0.0.1:' . $port . "/page)\n");

        \Safe\ob_start();
        $exit   = LinksChecker::main($dir->path);
        $output = \Safe\ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Warning for "nobody home" to "http://127.0.0.1:' . $port . '/page"', $output);
        self::assertStringContainsString('is not treated as a broken link', $output);
        self::assertStringNotContainsString('Bad link', $output);
    }

    /**
     * GitHub-owned hosts cannot be verified anonymously (private repos return
     * 404, indistinguishable from a genuine miss). With no token available the
     * checker must SKIP them — never fail — and emit a clear notice. This runs
     * fully offline: the skip happens before any HTTP request is made.
     *
     * @throws Exception
     */
    public function testGithubLinksAreSkippedWithoutToken(): void
    {
        $originalGhToken     = getenv(self::GH_TOKEN_VAR);
        $originalGithubToken = getenv(self::GITHUB_TOKEN_VAR);
        \Safe\putenv(self::GH_TOKEN_VAR);
        \Safe\putenv(self::GITHUB_TOKEN_VAR);

        try {
            $pathToProject    = __DIR__ . '/../../assets/linksChecker/projectWithGithubLink';
            $expectedExitCode = 0;
            $expectedOutput   = '
/README.md
----------

Skipped link check for "private repo" to "https://github.com/BallicomDev/ballicom-accountsiq"
reason: GitHub URLs cannot be verified anonymously'
                . ' (private repos return 404); set GH_TOKEN or GITHUB_TOKEN to enable checking.

Skipped link check for "raw file" to'
                . ' "https://raw.githubusercontent.com/BallicomDev/ballicom-accountsiq/main/README.md"
reason: GitHub URLs cannot be verified anonymously'
                . ' (private repos return 404); set GH_TOKEN or GITHUB_TOKEN to enable checking.
';
            self::assertResult($pathToProject, $expectedExitCode, $expectedOutput);
        } finally {
            $this->restoreEnv(self::GH_TOKEN_VAR, $originalGhToken);
            $this->restoreEnv(self::GITHUB_TOKEN_VAR, $originalGithubToken);
        }
    }

    /**
     * The project root is stripped from a docs file's directory to resolve a relative link, and a
     * root holding a regex metacharacter must be matched literally: `a+b` as a pattern does not
     * match the path `a+b`, so every relative link under docs/ was reported as broken.
     *
     * @throws Exception
     */
    public function testARelativeLinkResolvesUnderAProjectRootHoldingARegexMetacharacter(): void
    {
        $dir              = TempDir::create('linksCheckerMetacharacter');
        $this->tempDirs[] = $dir;
        $projectDir       = $dir->path . '/project+one (copy)';
        \Safe\mkdir($projectDir . '/docs', 0o755, true);
        \Safe\file_put_contents($projectDir . self::README, "[guide](docs/guide.md)\n");
        \Safe\file_put_contents($projectDir . '/docs/guide.md', "[other page](other.md)\n");
        \Safe\file_put_contents($projectDir . '/docs/other.md', "nothing to link\n");

        $this->assertResult($projectDir, 0, '');
    }

    /**
     * A docs file whose real path cannot be resolved is named as skipped, and the rest is
     * still checked (#241).
     *
     * @throws Exception
     */
    public function testADanglingSymlinkIsNamedAsSkippedAndTheRestStillChecked(): void
    {
        $dir              = TempDir::create('linksCheckerDangling');
        $this->tempDirs[] = $dir;
        $dir->write('README.md', "[guide](docs/guide.md)\n");
        $dir->write('docs/guide.md', "[gone](nothere.md)\n");
        \Safe\symlink('missing-target.md', $dir->path . '/docs/Gone.md');

        $this->assertResult($dir->path, 1, 'Markdown links skipped docs/Gone.md: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked

/docs/guide.md
--------------

Bad link for "gone" to "nothere.md"
');
    }

    /**
     * @throws Exception
     */
    protected function assertResult(string $pathToProject, int $expectedExitCode, string $expectedOutput): void
    {
        \Safe\ob_start();
        $actualExitCode = LinksChecker::main($pathToProject);
        $actualOutput   = \Safe\ob_get_clean();
        echo $actualOutput;
        self::assertSame($expectedOutput, $actualOutput);
        self::assertSame($expectedExitCode, $actualExitCode);
    }

    /**
     * Start PHP's built-in web server on a free localhost port, serving
     * arbitrary status codes via tests/assets/linksChecker/statusRouter.php.
     *
     * @return array{resource, string} the server process handle and base URL
     */
    private function startLocalStatusServer(): array
    {
        $router = \Safe\realpath(__DIR__ . '/../../assets/linksChecker/statusRouter.php');

        // Bind port 0 to let the OS pick a free port, then release it for the
        // server (a tiny reuse race, acceptable in a test on localhost).
        $probe = \Safe\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name  = \Safe\stream_socket_get_name($probe, false);
        $port  = (int)substr($name, (int)strrpos($name, ':') + 1);
        \Safe\fclose($probe);

        // \Safe\proc_open (this Safe version) only takes the string form; every
        // component is internally generated (no untrusted input) and escaped.
        // `exec` makes sh replace itself with the server, so proc_terminate()
        // reaches the real php -S process — without it the SIGTERM kills only
        // the sh wrapper and the orphaned server (holding an inherited fd on
        // phpunit's output pipe) hangs the surrounding qa run's tee forever.
        $command = \sprintf(
            'exec %s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($router),
        );
        $process = \Safe\proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        $baseUrl   = 'http://127.0.0.1:' . $port;
        $deadline  = microtime(true) + 10.0;
        $lastError = 'no attempt made';
        while (microtime(true) < $deadline) {
            try {
                @\Safe\get_headers($baseUrl . '/200');

                return [$process, $baseUrl];
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                usleep(50_000);
            }
        }

        proc_terminate($process);
        \Safe\proc_close($process);
        self::fail('Local status server failed to start on ' . $baseUrl . ': ' . $lastError);
    }

    /**
     * Build a throwaway project fixture whose README points at the local
     * status server (the fixture cannot be static — the port is dynamic).
     */
    private function createProjectWithNonFileLinks(string $baseUrl): string
    {
        $dir              = TempDir::create('linksCheckerNonFile');
        $this->tempDirs[] = $dir;
        $projectDir       = $dir->path;

        \Safe\file_put_contents(
            $projectDir . self::README,
            "contains links to external resource and in page links\n\n"
            . '[valid link](' . $baseUrl . "/200)\n\n"
            . '[invalid link](' . $baseUrl . "/404);\n\n"
            . "[ignored in page link](#link-target-not-validated)\n",
        );

        return $projectDir;
    }

    /**
     * @throws Exception
     */
    private function restoreEnv(string $name, string|false $original): void
    {
        if (false === $original) {
            \Safe\putenv($name);

            return;
        }

        \Safe\putenv($name . '=' . $original);
    }
}
