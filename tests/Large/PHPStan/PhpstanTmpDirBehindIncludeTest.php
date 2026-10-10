<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Tests\Support\FixtureConsumer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A project's own `tmpDir` is the project's decision, wherever in its configuration it sits (#140).
 * The phpstan and deadCode lanes write a tmpDir of their own into the outermost wrapper neon only
 * when the project sets none, and a line there would silently override the project's. These
 * fixtures set the project's tmpDir behind includes a NEON walk cannot follow: a path built from a
 * `%parameter%`, and a PHP configuration file. The real phar must then cache in the project's
 * directory and never in the lane's `var/qa/cache/<lane>`.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpstanTmpDirBehindIncludeTest extends TestCase
{
    private const string SHARED_CONFIG_ENV = 'PHPQA_FIXTURE_SHARED_CONFIG';

    private const string PROJECT_CACHE = 'ci-cache';

    private const string DEFAULT_NEON = '../' . FixtureConsumer::INSTALLED_LIBRARY . '/configDefaults/generic/phpstan.neon';

    private ?FixtureConsumer $consumer = null;

    protected function setUp(): void
    {
        $this->consumer = FixtureConsumer::create('phpstan-tmpdir-include');
    }

    protected function tearDown(): void
    {
        $this->consumer?->remove();
    }

    #[Test]
    public function thePhpstanLaneKeepsATmpDirSetBehindAParameterInclude(): void
    {
        $consumer = $this->consumer();
        $consumer->dir->write('shared/tmpdir.neon', \sprintf("parameters:\n    tmpDir: %s\n", $this->projectCache()));
        $consumer->dir->write('qaConfig/phpstan.neon', \sprintf("includes:\n    - %s\n    - %%env.%s%%/tmpdir.neon\n", self::DEFAULT_NEON, self::SHARED_CONFIG_ENV));

        $this->assertTheLaneCachesInTheProjectsTmpDir('stan', 'var/qa/cache/phpstan', [self::SHARED_CONFIG_ENV => $consumer->dir->path . '/shared']);
    }

    #[Test]
    public function thePhpstanLaneKeepsATmpDirSetInAPhpConfigInclude(): void
    {
        $this->writePhpInclude();

        $this->assertTheLaneCachesInTheProjectsTmpDir('stan', 'var/qa/cache/phpstan', []);
    }

    #[Test]
    public function theDeadCodeLaneKeepsATmpDirSetInAPhpConfigInclude(): void
    {
        $this->writePhpInclude();
        $this->consumer()->dir->write(
            'qaConfig/qa.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse LTS\\PHPQA\\Pipeline\\Config\\QaConfigBuilder;\n\n"
            . "return static fn (QaConfigBuilder \$qa): QaConfigBuilder => \$qa->withDeadCodeDetection(true)->withoutDeadCodeEntryPoints();\n",
        );

        $this->assertTheLaneCachesInTheProjectsTmpDir('dcd', 'var/qa/cache/deadCode', []);
    }

    private function writePhpInclude(): void
    {
        $consumer = $this->consumer();
        $consumer->dir->write('qaConfig/tmpdir.php', \sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['parameters' => ['tmpDir' => %s]];\n",
            var_export($this->projectCache(), true),
        ));
        $consumer->dir->write('qaConfig/phpstan.neon', \sprintf("includes:\n    - %s\n    - tmpdir.php\n", self::DEFAULT_NEON));
    }

    /** @param array<string, string> $env */
    private function assertTheLaneCachesInTheProjectsTmpDir(string $tool, string $laneCache, array $env): void
    {
        $consumer = $this->consumer();
        $process  = $consumer->qa(['TMPDIR' => $consumer->dir->mkdir('tmp'), ...$env], '-t', $tool);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertDirectoryDoesNotExist($consumer->dir->path . '/' . $laneCache, 'the lane overrode the project\'s tmpDir with its own: ' . $output);
        self::assertDirectoryExists($this->projectCache(), $output);
        self::assertGreaterThan(2, \count(\Safe\scandir($this->projectCache())), 'PHPStan wrote nothing under the project\'s tmpDir (only . and ..): ' . $output);
    }

    private function projectCache(): string
    {
        return $this->consumer()->dir->path . '/' . self::PROJECT_CACHE;
    }

    private function consumer(): FixtureConsumer
    {
        $consumer = $this->consumer;
        self::assertNotNull($consumer);

        return $consumer;
    }
}
