<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use LTS\PHPQA\Pipeline\Lane\RectorTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a Rector config refers to an API the bundled Rector no longer
 * has" (#298). Rector 2.7.0 removed `MemoryCacheStorage`; a config that still named it
 * failed before processing a file, in the consuming project, with Rector's own
 * "Expected an instance of this class" text, which names neither the config nor the line.
 *
 * Every shipped Rector config is booted against the committed `vendor-phar/rector.phar`
 * over a tiny project, so a Rector rebuild that removes or renames an API a shipped
 * config uses fails here and not in a consumer. A shipped config must also keep Rector's
 * cache inside the project: the system temp directory, which the test points at an empty
 * directory of its own, is left untouched.
 *
 * A project override is the other half: a copy of a shipped config in `qaConfig/rector.php`
 * must pass the lane, and a project config calling `cacheClass(MemoryCacheStorage::class)`
 * must stop the lane with a message naming the line and the fix.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ShippedRectorConfigsBootAgainstTheBundledPharTest extends TestCase
{
    private const string LIBRARY = __DIR__ . '/../../..';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-rector-boot');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require": {"php": "^8.5", "thecodingmachine/safe": "^3.0"}, "autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        $this->project->write('src/Probe.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Probe\n{\n}\n");
        $this->project->write('tests/ProbeTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class ProbeTest\n{\n}\n");
        $this->project->write('vendor/autoload.php', "<?php\n");
        $this->project->write('vendor/composer/installed.json', '{"packages": [], "dev": true}');
        $this->project->mkdir('systemTemp');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /** @return iterable<string, array{string}> */
    public static function shippedConfigs(): iterable
    {
        $configs = \Safe\glob(self::LIBRARY . '/configDefaults/generic/rector-*.php');
        self::assertNotSame([], $configs, 'no shipped Rector config found');
        foreach ($configs as $config) {
            yield basename($config) => [basename($config)];
        }
    }

    #[Test]
    #[DataProvider('shippedConfigs')]
    public function aShippedConfigBootsAndKeepsRectorsCacheInTheProject(string $config): void
    {
        $process = $this->rector(self::LIBRARY . '/configDefaults/generic/' . $config);

        self::assertContains($process->getExitCode(), [0, 2], 'the bundled Rector could not boot ' . $config . ":\n" . $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsString('[ERROR]', $process->getOutput() . $process->getErrorOutput());
        self::assertNotSame([], $this->filesUnder('var/qa/cache/rector'), $config . ' did not put Rector cache files under the project cache directory');
        self::assertSame([], $this->filesUnder('systemTemp'), $config . ' put Rector cache files in the system temp directory');
    }

    /**
     * The documented override is a copy of a shipped config in qaConfig/rector.php (docs/coding-standards.md);
     * rector-safe.php is left out because its vendor lookups are relative to the file's own directory.
     */
    #[Test]
    public function aProjectOverrideCopiedFromAShippedConfigPassesTheLane(): void
    {
        $this->project->write('qaConfig/rector.php', \Safe\file_get_contents(self::LIBRARY . '/configDefaults/generic/rector-php85.php'));

        $output = new BufferedOutput();
        $result = new RectorTool()->run(RealLaneContext::over($this->project->path, true, $output));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
        self::assertSame([], $this->filesUnder('systemTemp'), 'the lane put Rector cache files in the system temp directory');
    }

    #[Test]
    public function aProjectConfigStillCallingCacheClassWithMemoryCacheStorageStopsTheLaneWithTheLineAndTheFix(): void
    {
        $this->project->write('qaConfig/rector.php', implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;',
            'use Rector\Config\RectorConfig;',
            '',
            'return static function (RectorConfig $rectorConfig): void {',
            '    $rectorConfig->cacheClass(MemoryCacheStorage::class);',
            '};',
            '',
        ]));

        $output = new BufferedOutput();
        $result = new RectorTool()->run(RealLaneContext::over($this->project->path, true, $output));
        $printed = $output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, $printed);
        self::assertStringContainsString('qaConfig/rector.php:9', $printed);
        self::assertStringContainsString('$rectorConfig->cacheClass(MemoryCacheStorage::class);', $printed);
        self::assertStringContainsString('Remove the cacheClass(...) call', $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
    }

    private function rector(string $config): Process
    {
        $process = new Process(
            [\PHP_BINARY, \Safe\realpath(self::LIBRARY . '/vendor-phar/rector.phar'), 'process', '--config', \Safe\realpath($config), '--dry-run', '--clear-cache', '--no-progress-bar', 'src'],
            $this->project->path,
            [
                'rectorCacheDir' => $this->project->path . '/var/qa/cache/rector',
                'rectorIgnorePaths' => '',
                'TMPDIR' => $this->project->path . '/systemTemp',
            ],
            null,
            600,
        );
        $process->run();

        return $process;
    }

    /** @return list<string> the files below a project directory, relative to it */
    private function filesUnder(string $directory): array
    {
        $root = $this->project->path . '/' . $directory;
        if (!is_dir($root)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        return $files;
    }
}
