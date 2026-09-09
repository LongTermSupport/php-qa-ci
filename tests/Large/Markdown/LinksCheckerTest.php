<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Markdown;

use Exception;
use LTS\PHPQA\Markdown\LinksChecker;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Class LinksCheckerTest.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(LinksChecker::class)]
#[\PHPUnit\Framework\Attributes\Large]
final class LinksCheckerTest extends TestCase
{
    private const string GH_TOKEN_VAR = 'GH_TOKEN';

    private const string GITHUB_TOKEN_VAR = 'GITHUB_TOKEN';

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
        LinksChecker::main(__DIR__ . '/../../assets/linksChecker/projectNoReadme');
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
        $projectDir = sys_get_temp_dir() . '/linksCheckerNonFile' . bin2hex(random_bytes(8));
        \Safe\mkdir($projectDir, 0o755, true);

        \Safe\file_put_contents(
            $projectDir . '/README.md',
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
