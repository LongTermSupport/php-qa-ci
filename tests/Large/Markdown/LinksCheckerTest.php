<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Markdown;

use Exception;
use LTS\PHPQA\Markdown\LinksChecker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Class LinksCheckerTest.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @coversDefaultClass \LTS\PHPQA\Markdown\LinksChecker
 *
 * @internal
 *
 * @large
 */
final class LinksCheckerTest extends TestCase
{
    /**
     * @throws Exception
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @covers \LTS\PHPQA\Markdown\LinksChecker
     */
    public function testInvalidProject(): void
    {
        $pathToProject = __DIR__ . '/../../assets/linksChecker/projectWithBrokenLinks';
        $expectedExitCode = 1;
        $expectedOutput = '
/docs/linksCheckerTest.md
-------------------------

Bad link for "incorrect link" to "./../nothere.md"

/README.md
----------

Bad link for "incorrect link" to "./foo.md"
';
        $this->assertResult($pathToProject, $expectedExitCode, $expectedOutput);
    }

    /**
     * @throws Exception
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @covers \LTS\PHPQA\Markdown\LinksChecker
     */
    public function testMainNoReadmeFile(): void
    {
        $this->expectException(RuntimeException::class);
        LinksChecker::main(__DIR__ . '/../../assets/linksChecker/projectNoReadme');
    }

    /**
     * @throws Exception
     * @covers \LTS\PHPQA\Markdown\LinksChecker
     */
    public function testValidNoDocsFolder(): void
    {
        $pathToProject = __DIR__ . '/../../assets/linksChecker/projectWithReadmeNoDocsFolder';
        $expectedExitCode = 0;
        $expectedOutput = '';
        $this->assertResult($pathToProject, $expectedExitCode, $expectedOutput);
    }

    /**
     * @covers \LTS\PHPQA\Markdown\LinksChecker
     */
    public function testItHandlesNonFileLinks(): void
    {
        $pathToProject = __DIR__ . '/../../assets/linksChecker/projectWithNonFileLinks';
        $expectedExitCode = 1;
        $expectedOutput = '
/README.md
----------

Bad link for "invalid link" to "https://httpstat.us/404"
result: NULL
';
        $this->assertResult($pathToProject, $expectedExitCode, $expectedOutput);
    }

    /**
     * @throws Exception
     */
    protected function assertResult(string $pathToProject, int $expectedExitCode, string $expectedOutput): void
    {
        ob_start();
        $actualExitCode = LinksChecker::main($pathToProject);
        $actualOutput = ob_get_clean();
        echo $actualOutput;
        self::assertSame($expectedOutput, $actualOutput);
        self::assertSame($expectedExitCode, $actualExitCode);
    }
}
