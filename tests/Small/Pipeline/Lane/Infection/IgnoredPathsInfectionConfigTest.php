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

    private TempDir $project;

    private string $root;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-infcfg');
        $this->root    = $this->project->path;
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function nothingIsDerivedWhenNoIgnoredPathIsUnderASourceDirectory(): void
    {
        $config = $this->config(['source' => ['directories' => ['../src']]]);

        self::assertNull(new IgnoredPathsInfectionConfig()->derive($config, $this->ignored('tests/assets', 'srcLegacy')));
        self::assertNull(new IgnoredPathsInfectionConfig()->derive($config, $this->ignored()));
    }

    #[Test]
    public function anIgnoredPathUnderASourceDirectoryBecomesAnExcludeAnchoredAtThatDirectory(): void
    {
        $config = $this->config(['source' => ['directories' => ['../src'], 'excludes' => ['/ComposerPlugin/']]]);

        $derived = new IgnoredPathsInfectionConfig()->derive($config, $this->ignored('src/Legacy', 'src/Domain/Old.php'));

        self::assertSame(
            ['directories' => [$this->root . '/src'], 'excludes' => ['/ComposerPlugin/', '#^Legacy(?:/|$)#', '#^Domain/Old\.php(?:/|$)#']],
            $derived['source'] ?? null,
        );
    }

    #[Test]
    public function aRegexMetacharacterInAnIgnoredPathIsQuoted(): void
    {
        $derived = new IgnoredPathsInfectionConfig()->derive($this->config(['source' => ['directories' => ['../src']]]), $this->ignored('src/Le#ga.cy+'));

        self::assertSame(['#^Le\#ga\.cy\+(?:/|$)#'], $derived['source']['excludes'] ?? null);
    }

    #[Test]
    public function everyPathInfectionResolvesAgainstTheConfigDirectoryIsMadeAbsolute(): void
    {
        $config = $this->config([
            'timeout'   => 10,
            'bootstrap' => 'tests/bootstrap.php',
            'source'    => ['directories' => ['../src']],
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

        $derived = new IgnoredPathsInfectionConfig()->derive($config, $this->ignored('src/Legacy'));

        self::assertSame(
            [
                'timeout'   => 10,
                'bootstrap' => 'tests/bootstrap.php',
                'source'    => ['directories' => [$this->root . '/src'], 'excludes' => ['#^Legacy(?:/|$)#']],
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

    #[Test]
    public function anIgnoredSourceDirectoryIsDroppedRatherThanExcluded(): void
    {
        $config = $this->config(['source' => ['directories' => ['../src', '../lib']]]);

        $derived = new IgnoredPathsInfectionConfig()->derive($config, $this->ignored('lib'));

        self::assertSame(['directories' => [$this->root . '/src']], $derived['source'] ?? null);
    }

    #[Test]
    public function whenEverySourceDirectoryIsIgnoredNoneIsLeft(): void
    {
        $derived = new IgnoredPathsInfectionConfig()->derive($this->config(['source' => ['directories' => ['../src']]]), $this->ignored('src'));

        self::assertSame([], $derived['source']['directories'] ?? null);
    }

    #[Test]
    public function aConfigThatIsNotJsonIsReported(): void
    {
        $config = $this->project->write(self::CONFIG, '{"source": ');

        $this->expectException(JsonException::class);
        new IgnoredPathsInfectionConfig()->derive($config, $this->ignored('src/Legacy'));
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
