<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use LTS\PHPQA\PHPStan\Rules\ForbidAllowMockWithoutExpectationsRule;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "the shipped PHPUnit Rector configuration writes an attribute that a
 * shipped PHPStan rule bans" (issue 415).
 *
 * With PHPUnit 12.5.2 or newer locked, PHPUnitSetList::COMPOSER_BASED holds
 * AllowMockObjectsWhereParentClassRector (any subclass of Symfony's TypeTestCase) and
 * AllowMockObjectsForDataProviderRector (a #[DataProvider] test with a mock whose ->method()
 * has no expects()). Both add #[AllowMockObjectsWithoutExpectations], which
 * ForbidAllowMockWithoutExpectationsRule bans. Rector redoes the rewrite on every writable run,
 * so a consumer cannot hold the PHPStan-clean state. PHPStan wins: adjust Rector.
 *
 * The fixtures in tests/assets/rectorAllowMockAttribute/ are valid under the rule as written.
 * rector-phpunit.php is run, writable, over a copy of them, and the rule is then run over what
 * Rector wrote. A finding fails the test, naming the fixture. symfony/form is not a dependency,
 * so stubs/TypeTestCase.php stands in for the parent class; Rector matches it by name.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ShippedPhpunitRectorAddsNoBannedAttributeTest extends TestCase
{
    /** The php-qa-ci checkout whose shipped config and phars are under test. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** Test classes valid under the rule as written, and the stand-in parent class. */
    private const string FIXTURES = self::REPO_ROOT . '/tests/assets/rectorAllowMockAttribute';

    /** The rule's own identifier, so a rename of it cannot leave this test filtering for nothing. */
    private const string RULE_IDENTIFIER = ForbidAllowMockWithoutExpectationsRule::IDENTIFIER;

    /** Rector adds the attribute only from this PHPUnit (major * 10000 + minor * 100 + patch). */
    private const int FIRST_PHPUNIT_WITH_THE_RULES = 120_502;

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-rector-allow-mock');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require-dev": {"phpunit/phpunit": "*"}}');
        $this->project->write('composer.lock', \Safe\file_get_contents(self::REPO_ROOT . '/composer.lock'));
        // Rector reads the installed PHPUnit version from vendor/composer/installed.json.
        $this->project->write('vendor/composer/installed.json', \Safe\file_get_contents(self::REPO_ROOT . '/vendor/composer/installed.json'));
        $this->project->write('stubs/TypeTestCase.php', \Safe\file_get_contents(self::FIXTURES . '/stubs/TypeTestCase.php'));
        foreach ($this->fixtures() as $path) {
            $this->project->write('tests/' . basename($path), \Safe\file_get_contents($path));
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theLockedPhpunitIsNewEnoughForTheRulesToFire(): void
    {
        $lock = \Safe\json_decode(\Safe\file_get_contents(self::REPO_ROOT . '/composer.lock'), true);
        self::assertIsArray($lock);
        $version = null;
        $locked  = [...(\is_array($lock['packages'] ?? null) ? $lock['packages'] : []), ...(\is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [])];
        foreach ($locked as $package) {
            if (\is_array($package) && 'phpunit/phpunit' === ($package['name'] ?? null) && \is_string($package['version'] ?? null)) {
                $version = ltrim($package['version'], 'v');
            }
        }

        self::assertNotNull($version, 'composer.lock locks no phpunit/phpunit.');
        [$major, $minor, $patch] = array_map(intval(...), explode('.', $version)) + [0, 0, 0];
        self::assertGreaterThanOrEqual(self::FIRST_PHPUNIT_WITH_THE_RULES, $major * 10_000 + $minor * 100 + $patch, 'Locked PHPUnit is older than 12.5.2: Rector never adds the attribute, so this test would pass vacuously.');
    }

    #[Test]
    public function everyFixtureIsCleanUnderTheRuleBeforeRectorRuns(): void
    {
        self::assertSame([], $this->bannedAttributeFindings(), 'A fixture already carries the banned attribute, so it proves nothing about Rector.');
    }

    #[Test]
    public function thePositiveControlProvesRectorReachedTheFixtures(): void
    {
        $this->runShippedRector();

        // PreferPHPUnitSelfCallRector is in rector-phpunit.php's own rules(), unrelated to the attribute.
        self::assertStringContainsString('self::assertTrue(', $this->project->read('tests/PositiveControlTest.php'), 'Rector did not rewrite the control: the run never reached the fixtures, so a clean verdict proves nothing.');
    }

    #[Test]
    public function theShippedPhpunitRectorConfigWritesNoBannedAttribute(): void
    {
        $this->runShippedRector();

        $findings = $this->bannedAttributeFindings();

        self::assertSame([], $findings, 'rector-phpunit.php wrote #[AllowMockObjectsWithoutExpectations], which ' . self::RULE_IDENTIFIER
            . ' bans (PHPStan wins, adjust Rector: skip the rule named by `rector process --dry-run --output-format=json` in applied_rectors, with the reason beside the skip). '
            . 'The correct construction is createStub() for a dependency without expectations, and no attribute. Failing fixtures: '
            . implode(', ', array_unique(array_map(static fn (string $finding): string => explode(':', $finding, 2)[0], $findings))));
    }

    /**
     * @return list<string>
     */
    private function fixtures(): array
    {
        $found = array_values(array_filter(\Safe\glob(self::FIXTURES . '/*Test.php'), is_string(...)));
        sort($found);
        self::assertNotSame([], $found, 'tests/assets/rectorAllowMockAttribute holds no fixture.');

        return $found;
    }

    private function runShippedRector(): void
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', $this->pharPath('rector'), 'process', '--autoload-file', $this->autoloadFile(), '--config', \Safe\realpath(self::REPO_ROOT . '/configDefaults/generic/rector-phpunit.php'), '--clear-cache', '--no-progress-bar', 'tests'],
            $this->project->path,
            ['XDEBUG_MODE' => 'off', 'TMPDIR' => $this->project->path, 'rectorCacheDir' => $this->project->path . '/cache/rector', 'rectorIgnorePaths' => ''],
            null,
            600,
        );
        $process->run();
        self::assertContains($process->getExitCode(), [0, 2], "Rector could not process the fixtures:\n" . $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * Each finding as "<fixture>:<line>: <message>", from the banned-attribute rule alone.
     *
     * @return list<string>
     */
    private function bannedAttributeFindings(): array
    {
        $neon    = $this->project->write('phpstan.neon', "includes:\n    - " . \Safe\realpath(self::REPO_ROOT . '/rules-default.neon') . "\nparameters:\n    level: 0\n    paths:\n        - tests\n    scanFiles:\n        - stubs/TypeTestCase.php\n    tmpDir: " . $this->project->path . "/cache/phpstan\n");
        $process = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', $this->pharPath('phpstan'), 'analyse', '-c', $neon, '--no-progress', '--error-format=json', '--memory-limit=1G', '-a', \Safe\realpath(self::REPO_ROOT . '/vendor/autoload.php')],
            $this->project->path,
            ['TMPDIR' => $this->project->path],
            null,
            600,
        );
        $process->run();

        $report = \Safe\json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report, 'PHPStan wrote no JSON report: ' . $process->getErrorOutput());
        self::assertSame([], $report['errors'] ?? [], 'PHPStan reported internal errors.');

        $findings = [];
        $files    = $report['files'] ?? [];
        self::assertIsArray($files);
        foreach ($files as $file => $details) {
            self::assertIsArray($details);
            $messages = $details['messages'] ?? [];
            self::assertIsArray($messages);
            foreach ($messages as $message) {
                self::assertIsArray($message);
                if (self::RULE_IDENTIFIER !== ($message['identifier'] ?? null)) {
                    continue;
                }

                self::assertIsScalar($message['line'] ?? null);
                self::assertIsScalar($message['message'] ?? null);
                $findings[] = basename((string)$file) . ':' . $message['line'] . ': ' . $message['message'];
            }
        }

        sort($findings);

        return $findings;
    }

    /**
     * Rector resolves the parent class by reflection, so the stub must be loadable: an unresolvable
     * TypeTestCase leaves AllowMockObjectsWhereParentClassRector silent.
     */
    private function autoloadFile(): string
    {
        return $this->project->write('rector-autoload.php', "<?php\nrequire " . var_export(\Safe\realpath(self::REPO_ROOT . '/vendor/autoload.php'), true) . ";\nrequire __DIR__ . '/stubs/TypeTestCase.php';\n");
    }

    private function pharPath(string $tool): string
    {
        return \Safe\realpath(self::REPO_ROOT . '/vendor-phar/' . $tool . '.phar');
    }
}
