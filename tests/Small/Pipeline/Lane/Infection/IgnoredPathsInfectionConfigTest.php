<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use JsonException;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Infection\IgnoredPathsInfectionConfig;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Infection takes no exclusion on its command line, so the infection lane
 * hands it a derived copy of the resolved infection.json: every path Infection
 * resolves against the config's own directory made absolute, so the copy reads
 * the same from var/qa/, and each ignored path under a source directory added
 * to source.excludes as a regex anchored at that directory.
 *
 * @internal
 */
#[CoversClass(IgnoredPathsInfectionConfig::class)]
#[UsesClass(IgnoredPaths::class)]
#[Small]
final class IgnoredPathsInfectionConfigTest extends TestCase
{
    private const string CONFIG = 'qaConfig/infection.json';

    private const string SRC_FROM_CONFIG = '../src';

    private const string LEGACY = 'src/Legacy';

    private const string ANCHORED_LEGACY = '#^Legacy(?:/|$)#';

    private TempDir $project;

    private string $root;

    private string $src;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-infcfg');
        $this->root    = $this->project->path;
        $this->src     = $this->root . '/src';
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function nothingIsDerivedWhenNoIgnoredPathIsUnderASourceDirectory(): void
    {
        $config = $this->srcOnly();

        self::assertNull(new IgnoredPathsInfectionConfig()->derive($config, $this->ignored('tests/assets', 'srcLegacy')));
        self::assertNull(new IgnoredPathsInfectionConfig()->derive($config, $this->ignored()));
    }

    #[Test]
    public function anIgnoredPathUnderASourceDirectoryBecomesAnExcludeAnchoredAtThatDirectory(): void
    {
        $config = $this->config(['source' => ['directories' => [self::SRC_FROM_CONFIG], 'excludes' => ['/ComposerPlugin/']]]);

        $derived = new IgnoredPathsInfectionConfig()->derive($config, $this->ignored(self::LEGACY, 'src/Domain/Old.php'));

        self::assertSame(
            ['directories' => [$this->src], 'excludes' => ['/ComposerPlugin/', self::ANCHORED_LEGACY, '#^Domain/Old\.php(?:/|$)#']],
            $this->source($derived),
        );
    }

    #[Test]
    public function aRegexMetacharacterInAnIgnoredPathIsQuoted(): void
    {
        $derived = new IgnoredPathsInfectionConfig()->derive($this->srcOnly(), $this->ignored('src/Le#ga.cy+'));

        self::assertSame(['#^Le\#ga\.cy\+(?:/|$)#'], $this->source($derived)['excludes'] ?? null);
    }

    #[Test]
    public function everyPathInfectionResolvesAgainstTheConfigDirectoryIsMadeAbsolute(): void
    {
        $config = $this->config([
            'timeout'   => 10,
            'bootstrap' => 'tests/bootstrap.php',
            'source'    => ['directories' => [self::SRC_FROM_CONFIG]],
            'logs'      => [
                'text'        => '../var/qa/infection/log.txt',
                'summary'     => '../var/qa/infection/summary.txt',
                'json'        => 'json.log',
                'html'        => './html.html',
                'debug'       => '/abs/debug.txt',
                'perMutator'  => 'per.md',
                'gitlab'      => 'gitlab.json',
                'summaryJson' => 'summary.json',
                'github'      => true,
            ],
            'tmpDir'    => '../var/qa/infection/tmp',
            'phpUnit'   => ['configDir' => './', 'customPath' => '../bin/phpunit'],
            'phpStan'   => ['configDir' => '..', 'customPath' => '../bin/phpstan'],
            'mago'      => ['configDir' => 'mago', 'customPath' => 'bin/mago'],
            'debug'     => ['logFile' => 'debug.jsonl'],
            'mutators'  => ['@default' => true, 'global-ignoreSourceCodeByRegex' => ['#\[\s*[A-Za-z].*']],
        ]);
        $qa = $this->root . '/qaConfig';

        $derived = new IgnoredPathsInfectionConfig()->derive($config, $this->ignored(self::LEGACY));

        self::assertSame(
            [
                'timeout'   => 10,
                'bootstrap' => 'tests/bootstrap.php',
                'source'    => ['directories' => [$this->src], 'excludes' => [self::ANCHORED_LEGACY]],
                'logs'      => [
                    'text'        => $this->root . '/var/qa/infection/log.txt',
                    'summary'     => $this->root . '/var/qa/infection/summary.txt',
                    'json'        => $qa . '/json.log',
                    'html'        => $qa . '/html.html',
                    'debug'       => '/abs/debug.txt',
                    'perMutator'  => $qa . '/per.md',
                    'gitlab'      => $qa . '/gitlab.json',
                    'summaryJson' => $qa . '/summary.json',
                    'github'      => true,
                ],
                'tmpDir'    => $this->root . '/var/qa/infection/tmp',
                'phpUnit'   => ['configDir' => $qa, 'customPath' => $this->root . '/bin/phpunit'],
                'phpStan'   => ['configDir' => $this->root, 'customPath' => $this->root . '/bin/phpstan'],
                'mago'      => ['configDir' => $qa . '/mago', 'customPath' => $qa . '/bin/mago'],
                'debug'     => ['logFile' => $qa . '/debug.jsonl'],
                'mutators'  => ['@default' => true, 'global-ignoreSourceCodeByRegex' => ['#\[\s*[A-Za-z].*']],
            ],
            $derived,
            'bootstrap resolves against the working directory, so it is left as written',
        );
    }

    /**
     * Infection defaults an absent phpUnit, phpStan or mago configDir to the
     * config file's own directory, which for the copy would be var/qa/, so the
     * copy states the original directory explicitly.
     */
    #[Test]
    public function anAbsentConfigDirIsPinnedToTheOriginalConfigDirectory(): void
    {
        $config = $this->config([
            'source'  => ['directories' => [self::SRC_FROM_CONFIG]],
            'phpUnit' => ['customPath' => '../bin/phpunit'],
        ]);
        $qa = $this->root . '/qaConfig';

        $derived = new IgnoredPathsInfectionConfig()->derive($config, $this->ignored(self::LEGACY));

        self::assertIsArray($derived);
        self::assertSame(['configDir' => $qa, 'customPath' => $this->root . '/bin/phpunit'], $derived['phpUnit'] ?? null);
        self::assertSame(['configDir' => $qa], $derived['phpStan'] ?? null);
        self::assertSame(['configDir' => $qa], $derived['mago'] ?? null);
    }

    #[Test]
    public function anIgnoredSourceDirectoryIsDroppedRatherThanExcluded(): void
    {
        $config = $this->config(['source' => ['directories' => [self::SRC_FROM_CONFIG, '../lib']]]);

        $derived = new IgnoredPathsInfectionConfig()->derive($config, $this->ignored('lib'));

        self::assertSame(['directories' => [$this->src]], $this->source($derived));
    }

    #[Test]
    public function whenEverySourceDirectoryIsIgnoredNoneIsLeft(): void
    {
        $derived = new IgnoredPathsInfectionConfig()->derive($this->srcOnly(), $this->ignored('src'));

        self::assertSame([], $this->source($derived)['directories'] ?? null);
    }

    #[Test]
    public function aConfigThatIsNotJsonIsReported(): void
    {
        $config = $this->project->write(self::CONFIG, '{"source": ');

        $this->expectException(JsonException::class);
        new IgnoredPathsInfectionConfig()->derive($config, $this->ignored(self::LEGACY));
    }

    #[Test]
    public function aConfigThatIsNotAnObjectIsReported(): void
    {
        $config = $this->project->write(self::CONFIG, '"src"');

        try {
            new IgnoredPathsInfectionConfig()->derive($config, $this->ignored(self::LEGACY));
            self::fail('a config that is not an object was derived');
        } catch (JsonException $jsonException) {
            self::assertSame($config . ' does not hold a JSON object', $jsonException->getMessage());
        }
    }

    /**
     * @param array<array-key, mixed>|null $derived
     *
     * @return array<array-key, mixed>
     */
    private function source(?array $derived): array
    {
        self::assertIsArray($derived);
        $source = $derived['source'] ?? null;
        self::assertIsArray($source);

        return $source;
    }

    private function srcOnly(): string
    {
        return $this->config(['source' => ['directories' => [self::SRC_FROM_CONFIG]]]);
    }

    /** @param array<string, mixed> $config */
    private function config(array $config): string
    {
        return $this->project->write(self::CONFIG, \Safe\json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    private function ignored(string ...$relative): IgnoredPaths
    {
        return new IgnoredPaths($this->root, ...$relative);
    }
}
