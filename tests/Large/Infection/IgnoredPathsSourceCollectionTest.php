<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Infection;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Infection\IgnoredPathsInfectionConfig;
use LTS\PHPQA\Tests\Support\ChildEnvironment;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The derived infection.json the infection lane writes, read by the bundled
 * Infection itself.
 *
 * Collection runs Infection's own source collector, in a separate process, on
 * a fixture holding src/Legacy and src/Domain/Legacy: ignoring src/Legacy must
 * drop the first and keep the second. The schema test holds the derived
 * config to the Infection version shipped: every string setting in its schema
 * is either a path the derivation makes absolute or named here as not one, so
 * a path setting a later Infection adds cannot be left resolving against
 * var/qa/.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class IgnoredPathsSourceCollectionTest extends TestCase
{
    /** The Infection PHAR php-qa-ci ships. */
    private const string PHAR = __DIR__ . '/../../../vendor-phar/infection.phar';

    /** A project whose src/ holds Legacy/ and Domain/Legacy/. */
    private const string FIXTURE = __DIR__ . '/../../assets/infection/ignoredPaths';

    /** String settings in Infection's schema that are not resolved against the config's directory. */
    private const array NOT_PATHS = [
        '$schema'                   => 'the schema reference',
        'threads'                   => 'a count or "max"',
        'dotsPerRow'                => 'a count or "max"',
        'source.excludes'           => 'relative to each source directory, which the derivation makes absolute',
        'testFramework'             => 'a framework name',
        'staticAnalysisTool'        => 'a tool name',
        'staticAnalysisToolOptions' => 'command-line options',
        'bootstrap'                 => 'resolved against the working directory, which the lane keeps at the project root',
        'initialTestsPhpOptions'    => 'command-line options',
        'testFrameworkOptions'      => 'command-line options',
        'testFrameworkExtraArgs'    => 'command-line options',
    ];

    private TempDir $scratch;

    protected function setUp(): void
    {
        $this->scratch = TempDir::create('phpqa-infcollect');
    }

    protected function tearDown(): void
    {
        $this->scratch->remove();
    }

    #[Test]
    public function infectionCollectsEverySourceFileWhenNothingIsIgnored(): void
    {
        self::assertSame(
            ['Domain/Legacy/Deep.php', 'Kept.php', 'Legacy/Old.php'],
            $this->collect(\Safe\realpath(self::FIXTURE . '/qaConfig/infection.json')),
        );
    }

    #[Test]
    public function anIgnoredPathIsDroppedOnlyWhereItIsAnchored(): void
    {
        $fixture = \Safe\realpath(self::FIXTURE);
        $derived = new IgnoredPathsInfectionConfig()->derive($fixture . '/qaConfig/infection.json', new IgnoredPaths($fixture, 'src/Legacy'));
        self::assertNotNull($derived);

        $path = $this->scratch->write('var/qa/infection.json', \Safe\json_encode($derived, \JSON_UNESCAPED_SLASHES));

        self::assertSame(['Domain/Legacy/Deep.php', 'Kept.php'], $this->collect($path));
    }

    #[Test]
    public function everyStringSettingInTheShippedSchemaIsClassified(): void
    {
        $schema = \Safe\json_decode(\Safe\file_get_contents('phar://' . \Safe\realpath(self::PHAR) . '/resources/schema.json'), true);
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties'] ?? null);

        $unclassified = array_values(array_diff(
            $this->stringSettings($schema['properties'], ''),
            IgnoredPathsInfectionConfig::PATH_KEYS,
            array_keys(self::NOT_PATHS),
        ));

        self::assertSame([], $unclassified, 'a string setting the derivation neither makes absolute nor names as not a path');
    }

    /**
     * Dotted names of every setting whose value is a string or a list of
     * strings, outside the mutator settings.
     *
     * @param array<array-key, mixed> $properties
     *
     * @return list<string>
     */
    private function stringSettings(array $properties, string $prefix): array
    {
        $found = [];
        foreach ($properties as $name => $definition) {
            if (!\is_string($name) || !\is_array($definition) || 'mutators' === $name) {
                continue;
            }

            $dotted       = $prefix . $name;
            $alternatives = [$definition, ...$this->list($definition['oneOf'] ?? null), ...$this->list($definition['anyOf'] ?? null)];
            $types        = array_column(array_filter($alternatives, \is_array(...)), 'type');
            $itemType     = \is_array($definition['items'] ?? null) ? ($definition['items']['type'] ?? null) : null;
            if (\in_array('string', $types, true) || ('array' === ($definition['type'] ?? null) && 'string' === $itemType)) {
                $found[] = $dotted;
            }

            if (\is_array($definition['properties'] ?? null)) {
                $found = [...$found, ...$this->stringSettings($definition['properties'], $dotted . '.')];
            }
        }

        return $found;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }

    /** @return list<string> the files Infection collects, relative to the fixture's src/ */
    private function collect(string $configPath): array
    {
        $process = new Process([
            \PHP_BINARY,
            __DIR__ . '/../../assets/infection/collect-sources.php',
            \Safe\realpath(self::PHAR),
            $configPath,
            \Safe\realpath(self::FIXTURE . '/src'),
        ], null, ChildEnvironment::withoutXdebug());
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());

        return array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => '' !== $line));
    }
}
