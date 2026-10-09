<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two-lane flag contract: FULL keeps the SSoT floors; DIFF scopes
 * to the changed files via positional paths and enforces only the diff
 * covered floor. Both write the file logs at full verbosity.
 *
 * @internal
 */
#[CoversClass(InfectionArguments::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(InfectionOptionsDto::class)]
#[Small]
final class InfectionArgumentsTest extends TestCase
{
    private const string CFG_INFECTION_JSON = '/cfg/infection.json';

    private const string DEFAULT_DIFF_FLOOR = '--min-covered-msi=80';

    private const string SRC_CHANGED_PHP = '/p/src/Changed.php';

    private const string DIFF_BASE_MAIN = 'main';

    private const string LOG_ALL = '--log-verbosity=all';

    private const string WITH_UNCOVERED = '--with-uncovered';

    private const array COMMON = [
        '--coverage=/var/qa/phpunit_logs',
        '--skip-initial-tests',
        '--threads=4',
        '--configuration=/cfg/infection.json',
    ];

    #[Test]
    public function fullModeBuildsTheHistoricFloorInvocation(): void
    {
        $args = new InfectionArguments()->full($this->options(), '/var/qa/phpunit_logs', self::CFG_INFECTION_JSON);

        self::assertSame([...self::COMMON, '--min-msi=74', '--min-covered-msi=76', self::LOG_ALL], $args);
        self::assertNotContains(self::DEFAULT_DIFF_FLOOR, $args, 'the diff bar must not leak into a full run');
        foreach ($args as $arg) {
            self::assertStringStartsNotWith('--git-diff', $arg, 'a full run must not be git-diff scoped');
        }
    }

    #[Test]
    public function diffModeScopesToChangedFilesAndEnforcesNoNewEscapes(): void
    {
        $args = new InfectionArguments()->diff($this->options(diffBase: 'origin/main'), '/var/qa/phpunit_logs', self::CFG_INFECTION_JSON, self::SRC_CHANGED_PHP);

        self::assertSame([...self::COMMON, self::WITH_UNCOVERED, '--min-msi=80', self::DEFAULT_DIFF_FLOOR, '--ignore-msi-with-no-mutations', self::LOG_ALL, self::SRC_CHANGED_PHP], $args);
        self::assertNotContains('--filter=/p/src/Changed.php', $args, 'the deprecated --filter form must not be used');
        self::assertNotContains('--min-msi=74', $args, 'the whole-codebase floor is meaningless on a diff');
        self::assertNotContains('--min-covered-msi=76', $args, 'the whole-codebase covered floor is dropped in diff mode');
    }

    /**
     * A diff run mutates the uncovered code of its changed files too and holds the MSI, which
     * counts those mutants as escaped, to the diff floor: changed code no test runs fails the
     * run instead of producing no mutant and passing. With uncovered code mutated, a run that
     * still generates no mutant has changed no mutable code, which is the one case
     * --ignore-msi-with-no-mutations lets through.
     */
    #[Test]
    public function aDiffRunCountsUncoveredMutantsAgainstTheDiffFloor(): void
    {
        $args = new InfectionArguments()->diff($this->options(diffBase: self::DIFF_BASE_MAIN, diffCoveredMsi: 91), '/c', self::CFG_INFECTION_JSON, self::SRC_CHANGED_PHP);

        self::assertContains(self::WITH_UNCOVERED, $args);
        self::assertContains('--min-msi=91', $args);
        self::assertContains('--min-covered-msi=91', $args);
    }

    #[Test]
    public function aFullRunMutatesCoveredCodeOnly(): void
    {
        self::assertNotContains(self::WITH_UNCOVERED, new InfectionArguments()->full($this->options(), '/c', self::CFG_INFECTION_JSON));
    }

    /**
     * The file loggers (log.txt, summary-log.txt) are written at the same verbosity in both
     * lanes, so a project reading them after the run gets the same files whichever lane ran.
     */
    #[Test]
    public function bothLanesWriteTheLogsAtFullVerbosity(): void
    {
        self::assertContains(self::LOG_ALL, new InfectionArguments()->full($this->options(), '/c', self::CFG_INFECTION_JSON));
        self::assertContains(self::LOG_ALL, new InfectionArguments()->diff($this->options(diffBase: self::DIFF_BASE_MAIN), '/c', self::CFG_INFECTION_JSON, self::SRC_CHANGED_PHP));
    }

    /** A whole codebase always has mutable code, so a full run that generates no mutant fails. */
    #[Test]
    public function onlyTheDiffLaneAcceptsARunWithNoMutants(): void
    {
        self::assertNotContains('--ignore-msi-with-no-mutations', new InfectionArguments()->full($this->options(), '/c', self::CFG_INFECTION_JSON));
    }

    #[Test]
    public function positionalPathsAlwaysComeLast(): void
    {
        $args = new InfectionArguments()->diff($this->options(diffBase: self::DIFF_BASE_MAIN, onlyCovered: true), '/c', self::CFG_INFECTION_JSON, '/p/src/A.php', '/p/src/B.php');

        self::assertSame(['/p/src/A.php', '/p/src/B.php'], \array_slice($args, -2));
    }

    #[Test]
    public function diffCoveredMsiFloorIsOverridableForEquivalentMutants(): void
    {
        $args = new InfectionArguments()->diff($this->options(diffBase: 'origin/main', diffCoveredMsi: 95), '/c', self::CFG_INFECTION_JSON, self::SRC_CHANGED_PHP);

        self::assertContains('--min-covered-msi=95', $args);
        self::assertNotContains(self::DEFAULT_DIFF_FLOOR, $args, 'the overridden floor must REPLACE the default');
    }

    /**
     * Infection 0.35 mutates only covered code by default and has no `--only-covered`; passing it
     * makes Infection refuse the run (#74). The setting is kept, and changes nothing.
     */
    #[Test]
    public function onlyCoveredAddsNoOptionInEitherLane(): void
    {
        $full = new InfectionArguments()->full($this->options(onlyCovered: true), '/c', self::CFG_INFECTION_JSON);
        $diff = new InfectionArguments()->diff($this->options(diffBase: self::DIFF_BASE_MAIN, onlyCovered: true), '/c', self::CFG_INFECTION_JSON, self::SRC_CHANGED_PHP);

        self::assertSame(new InfectionArguments()->full($this->options(), '/c', self::CFG_INFECTION_JSON), $full);
        self::assertSame(new InfectionArguments()->diff($this->options(diffBase: self::DIFF_BASE_MAIN), '/c', self::CFG_INFECTION_JSON, self::SRC_CHANGED_PHP), $diff);
    }

    private function options(?string $diffBase = null, int $diffCoveredMsi = 80, bool $onlyCovered = false): InfectionOptionsDto
    {
        return new InfectionOptionsDto(
            enabled: true,
            threads: 4,
            onlyCovered: $onlyCovered,
            minMsi: 74,
            minCoveredMsi: 76,
            diffBase: $diffBase,
            diffCoveredMsi: $diffCoveredMsi,
        );
    }
}
