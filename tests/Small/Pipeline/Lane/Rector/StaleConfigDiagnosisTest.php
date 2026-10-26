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

    private const string PROJECT_CONFIG = 'qaConfig/rector.php';

    private const string DOCS_LINE = 'Why and how: docs/tools/rector.md in php-qa-ci, "A project config that sets its own cache".';

    private const string END_OF_DIFF = "    ----------- end diff -----------\n\n";

    private const string REMOVED_METHOD = 'cacheClass';

    private const string RECTOR_CONFIG_PATH = 'Rector/Config/RectorConfig';

    private const string PHP_WITH_CACHE_CLASS_CALL = "<?php\n\$c->cacheClass(M::class);\n";

    private const int EXIT_ERROR = 1;

    private const int EXIT_PENDING_CHANGES = 2;

    private const string DIFF_QUOTING_THE_MESSAGES = "\n1) src/Probe.php:7\n\n    ---------- begin diff ----------\n"
        . "@@ @@\n"
        . "     // Rector says: Expected an existing class name. Got: \"Foo\"\n"
        . "     // and: Expected an instance of this class or to this class among its parents\n"
        . "     // \"Rector\\Caching\\Contract\\ValueObject\\Storage\\CacheStorageInterface\". Got: \"Foo\"\n"
        . "-    if (0 === strpos(\$a, 'x')) {\n"
        . "+    if (str_starts_with(\$a, 'x')) {\n"
        . self::END_OF_DIFF;

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
        $config = $this->project->write(self::PROJECT_CONFIG, "<?php\n\nreturn static function (\$c): void {\n    \$c->cacheClass(MemoryCacheStorage::class);\n};\n");

        self::assertSame(
            [
                "Rector ('Project Specific') cannot load qaConfig/rector.php: it refers to a class the bundled Rector does not have.",
                '  qaConfig/rector.php:4: $c->cacheClass(MemoryCacheStorage::class);',
                'Rector does not accept "Rector\Caching\ValueObject\Storage\MemoryCacheStorage" as a cache storage.',
                'Remove the cacheClass(...) call. Rector then uses its own file cache, which php-qa-ci keeps under var/qa/cache/rector.',
                self::DOCS_LINE,
            ],
            $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config, self::EXIT_ERROR),
        );
    }

    #[Test]
    public function aRemovedCacheClassMethodNamesTheConfigTheLineAndTheFix(): void
    {
        $config = $this->project->write(self::PROJECT_CONFIG, "<?php\n\nreturn static function (\$c): void {\n    \$c->cacheClass(FileCacheStorage::class);\n};\n");

        self::assertSame(
            [
                "Rector ('Project Specific') cannot load qaConfig/rector.php: it calls a method the bundled Rector does not have.",
                '  qaConfig/rector.php:4: $c->cacheClass(FileCacheStorage::class);',
                'The bundled Rector has no RectorConfig::cacheClass().',
                'Remove the cacheClass(...) call. Rector then uses its own file cache, which php-qa-ci keeps under var/qa/cache/rector.',
                self::DOCS_LINE,
            ],
            $this->diagnosis()->lines(self::LABEL, $this->undefinedMethod(self::REMOVED_METHOD, '      '), $config, self::EXIT_ERROR),
        );
    }

    #[Test]
    public function aRemovedCacheClassMethodInAConfigWithNoSuchCallSaysSo(): void
    {
        $config = $this->project->write(self::PROJECT_CONFIG, "<?php\n\nrequire __DIR__ . '/shared.php';\n");

        $lines = $this->diagnosis()->lines(self::LABEL, $this->undefinedMethod(self::REMOVED_METHOD, '      '), $config, self::EXIT_ERROR);

        self::assertSame('  No cacheClass(...) call is in qaConfig/rector.php; look in the files it includes.', $lines[1]);
    }

    #[Test]
    public function anotherUndefinedRectorConfigMethodIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, $this->undefinedMethod('gone'), $config, self::EXIT_ERROR));
        self::assertSame([], $this->diagnosis()->lines(self::LABEL, "\n [ERROR] Call to undefined method Other\\Thing::cacheClass()\n\n", $config, self::EXIT_ERROR));
    }

    #[Test]
    public function aRemovedCacheClassMethodWrappedAcrossLinesIsStillRecognised(): void
    {
        $config  = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);
        $wrapped = "\n [ERROR] Call to undefined method Rector\\Config\\Rec\n         torConfig::cacheClass()   \n\n";

        self::assertNotSame([], $this->diagnosis()->lines(self::LABEL, $wrapped, $config, self::EXIT_ERROR));
    }

    #[Test]
    public function aRemovedCacheClassMessageQuotedInADiffIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);
        $diff   = "\n1) src/Probe.php:7\n\n    ---------- begin diff ----------\n"
            . '+    // ' . trim($this->undefinedMethod(self::REMOVED_METHOD)) . "\n"
            . self::END_OF_DIFF;

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, $diff, $config, self::EXIT_PENDING_CHANGES));
    }

    #[Test]
    public function aMessageWrappedInsideTheQuotedClassNameIsStillRecognised(): void
    {
        $config  = $this->project->write(self::CONFIG, "<?php\n\$c->cacheClass(MemoryCacheStorage::class);\n");
        $wrapped = str_replace(['Storage\Memory', 'Contract\Value'], ["Storage\\Mem\n         ory", "Contract\\\n         Value"], self::CACHE_REJECTED);

        $lines = $this->diagnosis()->lines(self::LABEL, $wrapped, $config, self::EXIT_ERROR);

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

        $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $this->project->path . '/rector.php', self::EXIT_ERROR);

        self::assertSame('  rector.php:5: $c->cacheClass(One::class);', $lines[1]);
        self::assertSame('  rector.php:6: $c->cacheClass (Two::class);', $lines[2]);
        self::assertStringStartsWith('Rector does not accept', $lines[3]);
        self::assertFileExists($config);
    }

    #[Test]
    public function atMostFiveCallsAreQuoted(): void
    {
        $config = $this->project->write(self::CONFIG, "<?php\n" . str_repeat("\$c->cacheClass(X::class);\n", 8));

        $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config, self::EXIT_ERROR);

        self::assertCount(5 + 4, $lines);
    }

    #[Test]
    public function aConfigWithNoCacheClassCallSaysSoAndStillGivesTheFix(): void
    {
        $config = $this->project->write(self::PROJECT_CONFIG, "<?php\n\nrequire __DIR__ . '/shared.php';\n");

        $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config, self::EXIT_ERROR);

        self::assertSame('  No cacheClass(...) call is in qaConfig/rector.php; look in the files it includes.', $lines[1]);
        self::assertStringStartsWith('Remove the cacheClass(...) call.', $lines[3]);
    }

    #[Test]
    public function aConfigOutsideTheProjectIsNamedByItsFullPath(): void
    {
        $other  = TempDir::create('phpqa-stale-config-other');
        $config = $other->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);

        try {
            $lines = $this->diagnosis()->lines(self::LABEL, self::CACHE_REJECTED, $config, self::EXIT_ERROR);
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
                self::DOCS_LINE,
            ],
            $this->diagnosis()->lines(self::LABEL, self::CLASS_MISSING, $config, self::EXIT_ERROR),
        );
    }

    #[Test]
    public function aMissingClassWithNoNamespaceIsMatchedByItsWholeName(): void
    {
        $config = $this->project->write(self::CONFIG, "<?php\n\$c->rule(Gone::class);\n");

        $lines = $this->diagnosis()->lines(self::LABEL, "[ERROR] Expected an existing class name. Got: \"Gone\"\n", $config, self::EXIT_ERROR);

        self::assertSame('  rector.php:2: $c->rule(Gone::class);', $lines[1]);
    }

    #[Test]
    public function aConfigThatIsNotThereIsDiagnosedWithoutQuotedLines(): void
    {
        $lines = $this->diagnosis()->lines(self::LABEL, self::CLASS_MISSING, $this->project->path . '/absent.php', self::EXIT_ERROR);

        self::assertCount(4, $lines);
        self::assertStringContainsString('which does not exist.', $lines[0]);
    }

    #[Test]
    public function anyOtherRectorOutputIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, " [ERROR] Undefined constant GONE\n", $config, self::EXIT_ERROR));
        self::assertSame([], $this->diagnosis()->lines(self::LABEL, '', $config, self::EXIT_ERROR));
        self::assertSame([], $this->diagnosis()->lines(self::LABEL, ' [OK] Rector is done!', $config, self::EXIT_ERROR));
    }

    #[Test]
    public function aPendingChangeDiffThatQuotesTheMessagesIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, self::DIFF_QUOTING_THE_MESSAGES, $config, self::EXIT_PENDING_CHANGES));
    }

    #[Test]
    public function anErrorExitWhoseOutputOnlyQuotesTheMessagesOutsideRectorsErrorBlockIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, self::DIFF_QUOTING_THE_MESSAGES, $config, self::EXIT_ERROR));
    }

    #[Test]
    public function aPendingChangeExitIsNeverDiagnosedEvenWhenTheErrorMarkerIsQuoted(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, self::DIFF_QUOTING_THE_MESSAGES . self::CLASS_MISSING, $config, self::EXIT_PENDING_CHANGES));
    }

    #[Test]
    public function aDiffLineQuotingTheWholeErrorMessageBesideAnUnrelatedErrorIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);
        $output = "\n1) src/A.php:7\n\n    ---------- begin diff ----------\n"
            . "@@ @@\n"
            . "     // [ERROR] Expected an existing class name. Got: \"Foo\"\n"
            . "-    if (0 === strpos(\$a, 'x')) {\n"
            . "+    if (str_starts_with(\$a, 'x')) {\n"
            . self::END_OF_DIFF
            . " [ERROR] Could not process \"src/B.php\" file, due to:\n"
            . "         \"Syntax error, unexpected T_STRING\".\n\n";

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, $output, $config, self::EXIT_ERROR));
    }

    #[Test]
    public function aDiffContextLineThatLooksLikeAnErrorBlockIsNotDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);
        $output = "\n1) src/A.php:7\n\n    ---------- begin diff ----------\n"
            . "@@ @@\n"
            . " \n"
            . " [ERROR] Expected an existing class name. Got: \"Foo\"\n"
            . " \n"
            . "-    if (0 === strpos(\$a, 'x')) {\n"
            . "+    if (str_starts_with(\$a, 'x')) {\n"
            . self::END_OF_DIFF
            . " [ERROR] Could not process \"src/B.php\" file, due to:\n"
            . "         \"Syntax error, unexpected T_STRING\".\n\n";

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, $output, $config, self::EXIT_ERROR));
    }

    #[Test]
    public function aMessageWrappedAcrossLinesIsOnlyJoinedWithinRectorsOwnErrorBlock(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);
        $output = " [ERROR] Could not process \"src/B.php\" file, due to:\n\n"
            . "     // Expected an existing class name.\n"
            . "     // Got: \"Foo\"\n\n";

        self::assertSame([], $this->diagnosis()->lines(self::LABEL, $output, $config, self::EXIT_ERROR));
    }

    #[Test]
    public function aGenuineErrorAfterADiffIsStillDiagnosed(): void
    {
        $config = $this->project->write(self::CONFIG, self::PHP_WITH_CACHE_CLASS_CALL);

        $lines = $this->diagnosis()->lines(self::LABEL, self::DIFF_QUOTING_THE_MESSAGES . self::CACHE_REJECTED, $config, self::EXIT_ERROR);

        self::assertStringContainsString('"Rector\Caching\ValueObject\Storage\MemoryCacheStorage"', $lines[2]);
    }

    private function undefinedMethod(string $method, string $trailing = ''): string
    {
        return "\n [ERROR] Call to undefined method " . str_replace('/', '\\', self::RECTOR_CONFIG_PATH) . '::' . $method . '()' . $trailing . "\n\n";
    }

    private function diagnosis(): StaleConfigDiagnosis
    {
        return new StaleConfigDiagnosis($this->project->path, $this->project->path . '/var/qa/cache/rector');
    }
}
