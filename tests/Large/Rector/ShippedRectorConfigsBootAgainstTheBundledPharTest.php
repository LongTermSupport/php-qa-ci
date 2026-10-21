<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use DirectoryIterator;
use FilesystemIterator;
use LTS\PHPQA\Pipeline\Lane\RectorTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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

    private const string SYSTEM_TEMP = 'systemTemp';

    private const string QA_CONFIG_RECTOR = 'qaConfig/rector.php';

    private const string PHP_OPEN_TAG = '<?php';

    private const string DECLARE_STRICT_TYPES = 'declare(strict_types=1);';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-rector-boot');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require": {"php": "^8.5", "thecodingmachine/safe": "^3.0"}, "autoload": {"psr-4": {"Probe\\\": "src/"}}}');
        $this->project->write('src/Probe.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Probe\n{\n}\n");
        $this->project->write('tests/ProbeTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class ProbeTest\n{\n}\n");
        $this->project->write('vendor/autoload.php', "<?php\n");
        $this->project->write('vendor/composer/installed.json', '{"packages": [], "dev": true}');
        $this->project->mkdir(self::SYSTEM_TEMP);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    #[DataProvider('shippedConfigs')]
    public function aShippedConfigBootsAndKeepsRectorsCacheInTheProject(string $config): void
    {
        $process = $this->rector(self::LIBRARY . '/configDefaults/generic/' . $config);

        self::assertContains($process->getExitCode(), [0, 2], 'the bundled Rector could not boot ' . $config . ":\n" . $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsString('[ERROR]', $process->getOutput() . $process->getErrorOutput());
        self::assertNotSame([], $this->filesUnder('var/qa/cache/rector'), $config . ' did not put Rector cache files under the project cache directory');
        self::assertSame([], $this->filesUnder(self::SYSTEM_TEMP), $config . ' put Rector cache files in the system temp directory');
    }

    /** @return iterable<string, array{string}> */
    public static function shippedConfigs(): iterable
    {
        $found = [];
        foreach (new DirectoryIterator(self::LIBRARY . '/configDefaults/generic') as $entry) {
            $file = $entry->getFilename();
            if (str_starts_with($file, 'rector-') && str_ends_with($file, '.php')) {
                $found[] = $file;
                yield $file => [$file];
            }
        }

        self::assertNotSame([], $found, 'no shipped Rector config found');
    }

    /**
     * The documented override is a copy of a shipped config in qaConfig/rector.php (docs/coding-standards.md);
     * rector-safe.php is left out because its vendor lookups are relative to the file's own directory.
     */
    #[Test]
    public function aProjectOverrideCopiedFromAShippedConfigPassesTheLane(): void
    {
        $this->project->write(self::QA_CONFIG_RECTOR, \Safe\file_get_contents(self::LIBRARY . '/configDefaults/generic/rector-php85.php'));

        $output = new BufferedOutput();
        $result = new RectorTool()->run(RealLaneContext::over($this->project->path, true, $output));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
        self::assertSame([], $this->filesUnder(self::SYSTEM_TEMP), 'the lane put Rector cache files in the system temp directory');
    }

    #[Test]
    public function aProjectConfigStillCallingCacheClassWithMemoryCacheStorageStopsTheLaneWithTheLineAndTheFix(): void
    {
        $this->project->write(self::QA_CONFIG_RECTOR, implode("\n", [
            self::PHP_OPEN_TAG,
            '',
            self::DECLARE_STRICT_TYPES,
            '',
            'use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;',
            'use Rector\Config\RectorConfig;',
            '',
            'return static function (RectorConfig $rectorConfig): void {',
            '    $rectorConfig->cacheClass(MemoryCacheStorage::class);',
            '};',
            '',
        ]));

        $output  = new BufferedOutput();
        $result  = new RectorTool()->run(RealLaneContext::over($this->project->path, true, $output));
        $printed = $output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, $printed);
        self::assertStringContainsString('qaConfig/rector.php:9', $printed);
        self::assertStringContainsString('$rectorConfig->cacheClass(MemoryCacheStorage::class);', $printed);
        self::assertStringContainsString('Remove the cacheClass(...) call', $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aProjectConfigNamingAClassTheBundledRectorLacksStopsTheLaneWithTheLine(): void
    {
        $this->project->write('rector.php', implode("\n", [
            self::PHP_OPEN_TAG,
            '',
            self::DECLARE_STRICT_TYPES,
            '',
            'use Rector\Config\RectorConfig;',
            '',
            'return static function (RectorConfig $rectorConfig): void {',
            '    $rectorConfig->rule(Rector\Removed\RemovedInRector27Rector::class);',
            '};',
            '',
        ]));

        $output  = new BufferedOutput();
        $result  = new RectorTool()->run(RealLaneContext::over($this->project->path, true, $output));
        $printed = $output->fetch();

        self::assertSame(ToolOutcomeEnum::Crashed, $result->outcome, $printed);
        self::assertStringContainsString('rector.php:8: $rectorConfig->rule(Rector\Removed\RemovedInRector27Rector::class);', $printed);
        self::assertStringContainsString('which does not exist', $printed);
        self::assertStringContainsString(RectorTool::IDENTIFIER, $printed);
    }

    /**
     * Rector prints a pending change's diff with its context lines, so a source comment that quotes
     * Rector's own Assert message appears in the output of a run that has nothing wrong with its config.
     */
    #[Test]
    public function aPendingChangeNearACommentQuotingRectorsMessageIsReportedAsPendingChanges(): void
    {
        $this->project->write(self::QA_CONFIG_RECTOR, \Safe\file_get_contents(self::LIBRARY . '/configDefaults/generic/rector-php85.php'));
        $this->project->write('src/Probe.php', implode("\n", [
            self::PHP_OPEN_TAG,
            '',
            self::DECLARE_STRICT_TYPES,
            '',
            'namespace Probe;',
            '',
            'final class Probe',
            '{',
            '    public function starts(string $a): bool',
            '    {',
            '        // Rector says: Expected an existing class name. Got: "Foo"',
            '        // and: Expected an instance of this class or to this class among its parents',
            '        // "Rector\Caching\Contract\ValueObject\Storage\CacheStorageInterface". Got: "Foo"',
            '        return 0 === strpos($a, \'x\');',
            '    }',
            '}',
            '',
        ]));

        $output  = new BufferedOutput();
        $result  = new RectorTool()->run(RealLaneContext::over($this->project->path, true, $output));
        $printed = $output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, $printed);
        self::assertStringContainsString('pending changes in a READ-ONLY run', $printed);
        self::assertStringNotContainsString('cannot load', $printed);
    }

    private function rector(string $config): Process
    {
        $process = new Process(
            [\PHP_BINARY, \Safe\realpath(self::LIBRARY . '/vendor-phar/rector.phar'), 'process', '--config', \Safe\realpath($config), '--dry-run', '--clear-cache', '--no-progress-bar', 'src'],
            $this->project->path,
            [
                'rectorCacheDir'    => $this->project->path . '/var/qa/cache/rector',
                'rectorIgnorePaths' => '',
                'TMPDIR'            => $this->project->path . '/' . self::SYSTEM_TEMP,
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
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        return $files;
    }
}
