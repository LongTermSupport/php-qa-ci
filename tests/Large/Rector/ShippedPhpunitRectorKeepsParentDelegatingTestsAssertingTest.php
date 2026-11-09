<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Rector;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "the shipped PHPUnit Rector configuration marks an asserting test as
 * not asserting".
 *
 * AddDoesNotPerformAssertionToNonAssertingTestRector, which PHPUnitSetList::COMPOSER_BASED
 * registers for PHPUnit 6.0 and newer, follows $this->method() and self::method() into the
 * method called but not parent::method(). A test that overrides an asserting parent test to
 * attach a #[DataProvider], and whose whole body is parent::<the same method>(), is read as
 * asserting nothing and given #[DoesNotPerformAssertions]. PHPUnit then reports it risky ("This
 * test is not expected to perform assertions but performed N assertions"), which fails a run
 * under failOnRisky. Rector redoes the rewrite on every writable run, so a consumer cannot hold
 * the passing state.
 *
 * The fixtures in tests/assets/rectorDoesNotPerformAssertions/ are correct as written and pass
 * PHPUnit without a risky result. rector-phpunit.php is run, writable, over a copy of them; the
 * delegating tests must come back without the attribute, and PHPUnit must still pass them.
 * Running the rule on its own over the same fixtures proves they reproduce the misfire, so a
 * clean verdict from the shipped config means the config keeps the rule out.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ShippedPhpunitRectorKeepsParentDelegatingTestsAssertingTest extends TestCase
{
    /** The php-qa-ci checkout whose shipped config and phars are under test. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** Test classes correct as written, and the abstract parent they delegate to. */
    private const string FIXTURES = self::REPO_ROOT . '/tests/assets/rectorDoesNotPerformAssertions';

    /**
     * The namespace of the rule that misfires. The class lives only inside rector.phar, so its
     * name is built from two constants: as one literal, Rector rewrites it to ::class, which
     * PHPStan then reports as a class it cannot find.
     */
    private const string RULE_NAMESPACE = 'Rector\PHPUnit\PHPUnit60\Rector\ClassMethod';

    /** The short name of the rule that misfires. */
    private const string RULE_SHORT_NAME = 'AddDoesNotPerformAssertionToNonAssertingTestRector';

    /** The rule that misfires, by the class name Rector registers it under. */
    private const string RULE = self::RULE_NAMESPACE . '\\' . self::RULE_SHORT_NAME;

    /** The fixture whose tests only delegate to their asserting parent. */
    private const string DELEGATING_FIXTURE = 'tests/ParentDelegatingTest.php';

    /** The fixture that genuinely asserts nothing and already carries the attribute. */
    private const string NON_ASSERTING_FIXTURE = 'tests/AlreadyNonAssertingTest.php';

    /** The directory of the temp project the tools run over. */
    private const string TESTS_DIR = 'tests';

    /** The rule writes the attribute only when PHPUnit has it (major * 10000 + minor * 100 + patch). */
    private const int FIRST_PHPUNIT_WITH_THE_ATTRIBUTE = 100_000;

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-rector-does-not-perform-assertions');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project", "require-dev": {"phpunit/phpunit": "*"}}');
        $this->project->write('composer.lock', \Safe\file_get_contents(self::REPO_ROOT . '/composer.lock'));
        // Rector reads the installed PHPUnit version from vendor/composer/installed.json.
        $this->project->write('vendor/composer/installed.json', \Safe\file_get_contents(self::REPO_ROOT . '/vendor/composer/installed.json'));
        foreach ($this->fixtures() as $path) {
            $this->project->write(self::TESTS_DIR . '/' . basename($path), \Safe\file_get_contents($path));
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theLockedPhpunitHasTheAttributeTheRuleWrites(): void
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
        self::assertGreaterThanOrEqual(self::FIRST_PHPUNIT_WITH_THE_ATTRIBUTE, $major * 10_000 + $minor * 100 + $patch, 'Locked PHPUnit is older than 10.0: the rule writes a docblock annotation, not the attribute PHPUnit reports on, so this test would prove less than it says.');
    }

    #[Test]
    public function theFixturesPassPhpunitWithoutRiskBeforeRectorRuns(): void
    {
        self::assertSame([], $this->markedMethods(self::DELEGATING_FIXTURE), 'A delegating fixture already says it performs no assertions, so it proves nothing about Rector.');
        $this->assertPhpunitPassesWithoutRisk('The fixtures as written do not pass PHPUnit cleanly, so they cannot show what Rector broke');
    }

    #[Test]
    public function theRuleOnItsOwnStillMisfiresOnTheFixtures(): void
    {
        $cache = $this->project->path . '/cache/rector-rule-alone';
        $this->project->mkdir('cache/rector-rule-alone/files');
        $this->project->mkdir('cache/rector-rule-alone/container');

        $config = $this->project->write('rector-rule-alone.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn static function (Rector\\Config\\RectorConfig \$rectorConfig): void {\n"
            . '    $rectorConfig->cacheDirectory(' . var_export($cache . '/files', true) . ");\n"
            . '    $rectorConfig->containerCacheDirectory(' . var_export($cache . '/container', true) . ");\n"
            . '    $rectorConfig->rule(' . var_export(self::RULE, true) . ");\n};\n");

        $this->runRector($config);

        self::assertSame(['itCountsTheLetters', 'testGreetsByName'], $this->markedMethods(self::DELEGATING_FIXTURE), 'The rule on its own no longer marks the parent-delegating tests: either the fixture stopped reproducing the misfire, or a Rector release fixed it. '
            . 'If the latter, the skip of ' . self::RULE . ' in rector-phpunit.php may be dropped; either way this test proves nothing until the fixture reproduces again or the test is retired.');
    }

    #[Test]
    public function thePositiveControlProvesRectorReachedTheFixtures(): void
    {
        $this->runShippedRector();

        // PreferPHPUnitSelfCallRector is in rector-phpunit.php's own rules(), unrelated to the attribute.
        self::assertStringContainsString('self::assertTrue(', $this->project->read(self::TESTS_DIR . '/PositiveControlTest.php'), 'Rector did not rewrite the control: the run never reached the fixtures, so a clean verdict proves nothing.');
    }

    #[Test]
    public function theShippedPhpunitRectorConfigLeavesParentDelegatingTestsAsserting(): void
    {
        $this->runShippedRector();

        self::assertSame([], $this->markedMethods(self::DELEGATING_FIXTURE), 'rector-phpunit.php marked these tests #[DoesNotPerformAssertions], yet each delegates to a parent test that asserts, so PHPUnit reports them risky. '
            . 'Skip ' . self::RULE . ' in rector-phpunit.php, with the reason beside the skip: it does not follow parent:: calls.');
    }

    #[Test]
    public function aGenuinelyNonAssertingTestKeepsItsAttributeOnce(): void
    {
        $this->runShippedRector();

        self::assertSame(['itConstructsWithoutThrowing'], $this->markedMethods(self::NON_ASSERTING_FIXTURE), 'rector-phpunit.php removed or duplicated #[DoesNotPerformAssertions] on a test that asserts nothing, where the attribute is correct.');
    }

    #[Test]
    public function theRectorWrittenFixturesPassPhpunitWithoutRisk(): void
    {
        $this->runShippedRector();

        $this->assertPhpunitPassesWithoutRisk('PHPUnit, run under failOnRisky over what rector-phpunit.php wrote, did not pass cleanly');
    }

    /**
     * @return list<string>
     */
    private function fixtures(): array
    {
        $found = array_values(array_filter(\Safe\glob(self::FIXTURES . \DIRECTORY_SEPARATOR . '*.php'), is_string(...)));
        sort($found);
        self::assertNotSame([], $found, 'tests/assets/rectorDoesNotPerformAssertions holds no fixture.');

        return $found;
    }

    private function runShippedRector(): void
    {
        $this->runRector(\Safe\realpath(self::REPO_ROOT . '/configDefaults/generic/rector-phpunit.php'));
    }

    private function runRector(string $config): void
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', $this->pharPath('rector'), 'process', '--autoload-file', \Safe\realpath(self::REPO_ROOT . '/vendor/autoload.php'), '--config', $config, '--clear-cache', '--no-progress-bar', self::TESTS_DIR],
            $this->project->path,
            ['XDEBUG_MODE' => 'off', 'TMPDIR' => $this->project->path, 'rectorCacheDir' => $this->project->path . '/cache/rector', 'rectorIgnorePaths' => ''],
            null,
            600,
        );
        $process->run();
        self::assertContains($process->getExitCode(), [0, 2], "Rector could not process the fixtures:\n" . $process->getOutput() . $process->getErrorOutput());
    }

    private function assertPhpunitPassesWithoutRisk(string $context): void
    {
        $this->project->write('phpunit-bootstrap.php', "<?php\n\ndeclare(strict_types=1);\n\nrequire " . var_export(\Safe\realpath(self::REPO_ROOT . '/vendor/autoload.php'), true) . ";\nrequire __DIR__ . '/tests/AbstractGreetingTestCase.php';\n");
        // The fixtures' own configuration: a run over the temp project never reads php-qa-ci's phpunit.xml.
        $config  = $this->project->write('phpunit.xml', "<?xml version=\"1.0\"?>\n<phpunit bootstrap=\"phpunit-bootstrap.php\" failOnRisky=\"true\" failOnWarning=\"true\">\n  <testsuites>\n    <testsuite name=\"fixtures\">\n      <directory suffix=\"Test.php\">" . self::TESTS_DIR . "</directory>\n    </testsuite>\n  </testsuites>\n</phpunit>\n");
        $process = new Process(
            [\PHP_BINARY, '-d', 'display_errors=stderr', \Safe\realpath(self::REPO_ROOT . '/vendor/phpunit/phpunit/phpunit'), '--configuration', $config, '--do-not-record-test-run-history', '--fail-on-risky', '--display-all-issues'],
            $this->project->path,
            ['XDEBUG_MODE' => 'off', 'TMPDIR' => $this->project->path],
            null,
            600,
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $context . ":\n" . $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsStringIgnoringCase('risky', $process->getOutput(), $context . ":\n" . $process->getOutput());
    }

    /**
     * The test methods of a fixture, as Rector left it, that say they perform no assertions:
     * by the attribute, in either spelling, or by the annotation the rule writes for PHPUnit
     * older than 10.
     *
     * @return list<string>
     */
    private function markedMethods(string $fixture): array
    {
        $matches = [];
        \Safe\preg_match_all('/(?:DoesNotPerformAssertions\]|@doesNotPerformAssertions\b)[^{]*?\bfunction\s+(\w+)/', $this->project->read($fixture), $matches);
        self::assertIsArray($matches, 'preg_match_all wrote no match array.');
        $names = $matches[1] ?? [];
        self::assertIsArray($names, 'preg_match_all wrote no method-name group.');
        $methods = array_values(array_filter($names, is_string(...)));
        sort($methods);

        return $methods;
    }

    private function pharPath(string $tool): string
    {
        return \Safe\realpath(self::REPO_ROOT . '/vendor-phar/' . $tool . '.phar');
    }
}
