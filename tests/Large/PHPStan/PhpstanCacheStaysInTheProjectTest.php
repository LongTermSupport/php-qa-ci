<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Tests\Support\FixtureConsumer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a PHPStan verdict depends on a cache outside the project" (#123). PHPStan's
 * default tmpDir is sys_get_temp_dir()/phpstan, which every checkout on the host shares, and a stale
 * entry there failed a clean tree in one worktree only. The real phar runs each lane in a fixture
 * consumer with TMPDIR pointed at a directory the test watches: PHPStan must write its cache under
 * the consumer's var/qa/cache/ and nothing under the system temp directory.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpstanCacheStaysInTheProjectTest extends TestCase
{
    private ?FixtureConsumer $consumer = null;

    protected function setUp(): void
    {
        $this->consumer = FixtureConsumer::create('phpstan-cache-consumer');
    }

    protected function tearDown(): void
    {
        $this->consumer?->remove();
    }

    #[Test]
    public function thePhpstanLaneCachesUnderTheProjectsVarQa(): void
    {
        $this->assertTheLaneCachesInside('stan', 'var/qa/cache/phpstan');
    }

    #[Test]
    public function theDeadCodeLaneCachesUnderTheProjectsVarQa(): void
    {
        $consumer = $this->consumer;
        self::assertNotNull($consumer);
        $consumer->dir->write(
            'qaConfig/qa.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse LTS\\PHPQA\\Pipeline\\Config\\QaConfigBuilder;\n\n"
            . "return static fn (QaConfigBuilder \$qa): QaConfigBuilder => \$qa->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints();\n",
        );

        $this->assertTheLaneCachesInside('dcd', 'var/qa/cache/deadCode');
    }

    private function assertTheLaneCachesInside(string $tool, string $cacheDir): void
    {
        $consumer = $this->consumer;
        self::assertNotNull($consumer);
        $systemTemp = $consumer->dir->mkdir('tmp');

        $process = $consumer->qa(['TMPDIR' => $systemTemp], '-t', $tool);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertDirectoryDoesNotExist($systemTemp . '/phpstan', 'PHPStan fell back to the shared system temp directory: ' . $output);
        $absolute = $consumer->dir->path . '/' . $cacheDir;
        self::assertDirectoryExists($absolute, $output);
        self::assertGreaterThan(2, \count(\Safe\scandir($absolute)), 'PHPStan wrote nothing under ' . $cacheDir . ' (only . and ..): ' . $output);
    }
}
