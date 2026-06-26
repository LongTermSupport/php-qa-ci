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
        try {
            @\Safe\get_headers('https://httpstat.us/200');
        } catch (Throwable) {
            self::markTestSkipped('httpstat.us is not reachable (e.g. CI environment)');
        }

        $pathToProject    = __DIR__ . '/../../assets/linksChecker/projectWithNonFileLinks';
        $expectedExitCode = 1;
        $expectedOutput   = '
/README.md
----------

Bad link for "invalid link" to "https://httpstat.us/404"
result: HTTP status: 404
';
        self::assertResult($pathToProject, $expectedExitCode, $expectedOutput);
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
        $originalGhToken     = getenv('GH_TOKEN');
        $originalGithubToken = getenv('GITHUB_TOKEN');
        \Safe\putenv('GH_TOKEN');
        \Safe\putenv('GITHUB_TOKEN');

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
            $this->restoreEnv('GH_TOKEN', $originalGhToken);
            $this->restoreEnv('GITHUB_TOKEN', $originalGithubToken);
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
