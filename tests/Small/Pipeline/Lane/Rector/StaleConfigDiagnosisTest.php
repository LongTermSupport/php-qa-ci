<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Rector;

use LTS\PHPQA\Pipeline\Lane\Rector\StaleConfigDiagnosis;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StaleConfigDiagnosis::class)]
#[Small]
final class StaleConfigDiagnosisTest extends TestCase
{
    private const string LABEL = 'Project Specific';

    private const string CONFIG = 'rector.php';

    private const string CACHE_REJECTED = "\n [ERROR] Expected an instance of this class or to this class among its parents  \n"
        . "         \"Rector\\Caching\\Contract\\ValueObject\\Storage\\CacheStorageInterface\".   \n"
        . "         Got: \"Rector\\Caching\\ValueObject\\Storage\\MemoryCacheStorage\"           \n\n";

    private const string CLASS_MISSING = "\n [ERROR] Expected an existing class name. Got: \"Rector\\Gone\\GoneRector\"         \n\n";

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-stale-config');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aRejectedCacheClassNamesTheConfigTheLineAndTheFix(): void
    {
        $config = $this->project->write('qaConfig/rector.php', "<?php\n\nreturn static function (\$c): void {\n    \$c->cacheClass(MemoryCacheStorage::class);\n};\n");

        self::assertSame(
            [
                "Rector ('Project Specific') cannot load qaConfig/rector.php: it refers to a class the bundled Rector does not have.",
                '  qaConfig/rector.php:4: $c->cacheClass(MemoryCacheStorage::class);',
                'Rector does not accept "Rector\Caching\ValueObject\Storage\MemoryCacheStorage" as a cache storage.',
                "Remove the cacheClass(...) call. The shipped cache setting applies: Rector's FileCacheStorage, kept under var/qa/cache/rector, exported to the config as rectorCacheDir.",
                'Why and how: docs/tools/rector.md in php-qa-ci, "A project config that sets its own cache".',
            ],
            $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config),
        );
    }

    #[Test]
    public function aMessageWrappedInsideTheQuotedClassNameIsStillRecognised(): void
    {
        $config  = $this->project->write(self::CONFIG, "<?php\n\$c->cacheClass(MemoryCacheStorage::class);\n");
        $wrapped = str_replace(['Storage\Memory', 'Contract\Value'], ["Storage\\Mem\n         ory", "Contract\\\n         Value"], self::CACHE_REJECTED);

        $lines = $this->diagnosis()->lines(self::LABEL, $wrapped, $config);

        self::assertStringContainsString('"Rector\Caching\ValueObject\Storage\MemoryCacheStorage"', $lines[2]);
    }

    #[Test]
    public function everyCacheClassCallIsQuotedAndACommentedOneIsNot(): void
    {
        $config = $this->project->write(self::CONFIG, implode("\n", [
            '<?php',
            '// $c->cacheClass(Old::class);',
            '    /* $c->cacheClass(Older::class); */',
            ' * $c->cacheClass(Oldest::class);',
            '$c->cacheClass(One::class);',
            '$c->cacheClass (Two::class);',
            '',
        ]));

        $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $this->project->path . '/rector.php');

        self::assertSame('  rector.php:5: $c->cacheClass(One::class);', $lines[1]);
        self::assertSame('  rector.php:6: $c->cacheClass (Two::class);', $lines[2]);
        self::assertStringStartsWith('Rector does not accept', $lines[3]);
        self::assertFileExists($config);
    }

    #[Test]
    public function atMostFiveCallsAreQuoted(): void
    {
        $config = $this->project->write(self::CONFIG, "<?php\n" . str_repeat("\$c->cacheClass(X::class);\n", 8));

        $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config);

        self::assertCount(5 + 4, $lines);
    }

    #[Test]
    public function aConfigWithNoCacheClassCallSaysSoAndStillGivesTheFix(): void
    {
        $config = $this->project->write('qaConfig/rector.php', "<?php\n\nrequire __DIR__ . '/shared.php';\n");

        $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config);

        self::assertSame('  No cacheClass(...) call is in qaConfig/rector.php; look in the files it includes.', $lines[1]);
        self::assertStringStartsWith('Remove the cacheClass(...) call.', $lines[3]);
    }

    #[Test]
    public function aConfigOutsideTheProjectIsNamedByItsFullPath(): void
    {
        $other  = TempDir::create('phpqa-stale-config-other');
        $config = $other->write(self::CONFIG, "<?php\n\$c->cacheClass(M::class);\n");

        try {
            $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config);
        } finally {
            $other->remove();
        }

        self::assertStringContainsString('cannot load ' . $config . ':', $lines[0]);
        self::assertSame('  ' . $config . ':2: $c->cacheClass(M::class);', $lines[1]);
    }

    #[Test]
    public function aMissingClassNamesTheClassAndTheLinesThatMentionIt(): void
    {
        $config = $this->project->write(self::CONFIG, "<?php\nuse Rector\\Gone\\GoneRector;\n\$c->rule(GoneRector::class);\n// GoneRector was here\n");

        self::assertSame(
            [
                "Rector ('Project Specific') cannot load rector.php: it refers to the class Rector\\Gone\\GoneRector, which does not exist.",
                '  rector.php:2: use Rector\Gone\GoneRector;',
                '  rector.php:3: $c->rule(GoneRector::class);',
                "The bundled Rector no longer has this class, or the project's autoloader does not provide it.",
                'Remove the reference or replace it with what the bundled Rector offers instead.',
                'Why and how: docs/tools/rector.md in php-qa-ci, "A project config that sets its own cache".',
            ],
            $this->diagnosis()->lines(self::LABEL, self::CLASS_MISSING, $config),
        );
    }

    #[Test]
    public function aMissingClassWithNoNamespaceIsMatchedByItsWholeName(): void
    {
        $config = $this->project->write(self::CONFIG, "<?php\n\$c->rule(Gone::class);\n");

        $lines = $this->diagnosis()->lines(self::LABEL, "[ERROR] Expected an existing class name. Got: \"Gone\"\n", $config);

        self::assertSame('  rector.php:2: $c->rule(Gone::class);', $lines[1]);
    }

    #[Test]
    public function aConfigThatIsNotThereIsDiagnosedWithoutQuotedLines(): void
    {
        $lines = $this->diagnosis()->lines(self::LABEL, self::CLASS_MISSING, $this->project->path . '/absent.php');

        self::assertCount(4, $lines);
        self::assertStringContainsString('which does not exist.', $lines[0]);
    }

    #[Test]
    public function anyOtherRectorOutputIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, "<?php\n\$c->cacheClass(M::class);\n");

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, " [ERROR] Undefined constant GONE\n", $config));
        self::assertSame([], $this->diagnosis()->lines(self::LABEL, '', $config));
        self::assertSame([], $this->diagnosis()->lines(self::LABEL, ' [OK] Rector is done!', $config));
    }

    private function diagnosis(): StaleConfigDiagnosis
    {
        return new StaleConfigDiagnosis($this->project->path, $this->project->path . '/var/qa/cache/rector');
    }
}
