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
 * Pins the two-lane flag contract the Bash fragment's runInfection() had:
 * FULL keeps the SSoT floors and the verbose report; DIFF scopes to the
 * changed files via positional paths, enforces only the diff covered floor
 * and drops the verbose report.
 *
 * @internal
 */
#[CoversClass(InfectionArguments::class)]
#[Small]
final class InfectionArgumentsTest extends TestCase
{
    private const array COMMON = [
        '--coverage=/var/qa/phpunit_logs',
        '--skip-initial-tests',
        '--threads=4',
        '--configuration=/cfg/infection.json',
    ];

    #[Test]
    public function fullModeBuildsTheHistoricFloorInvocation(): void
    {
        $args = new InfectionArguments()->full($this->options(), '/var/qa/phpunit_logs', '/cfg/infection.json');

        self::assertSame([...self::COMMON, '--min-msi=74', '--min-covered-msi=76', '--log-verbosity=all'], $args);
        self::assertNotContains('--min-covered-msi=100', $args, 'the diff bar must not leak into a full run');
        foreach ($args as $arg) {
            self::assertStringStartsNotWith('--git-diff', $arg, 'a full run must not be git-diff scoped');
        }
    }

    #[Test]
    public function diffModeScopesToChangedFilesAndEnforcesNoNewEscapes(): void
    {
        $args = new InfectionArguments()->diff($this->options(diffBase: 'origin/main'), '/var/qa/phpunit_logs', '/cfg/infection.json', '/p/src/Changed.php');

        self::assertSame([...self::COMMON, '--min-covered-msi=100', '/p/src/Changed.php'], $args);
        self::assertNotContains('--filter=/p/src/Changed.php', $args, 'the deprecated --filter form must not be used');
        self::assertNotContains('--min-msi=74', $args, 'the whole-codebase floor is meaningless on a diff');
        self::assertNotContains('--min-covered-msi=76', $args, 'the whole-codebase covered floor is dropped in diff mode');
        self::assertNotContains('--log-verbosity=all', $args, 'diff mode drops the verbose console report');
    }

    #[Test]
    public function positionalPathsAlwaysComeLast(): void
    {
        $args = new InfectionArguments()->diff($this->options(diffBase: 'main', onlyCovered: true), '/c', '/cfg/infection.json', '/p/src/A.php', '/p/src/B.php');

        self::assertSame(['/p/src/A.php', '/p/src/B.php'], \array_slice($args, -2));
        self::assertSame('--only-covered', $args[0]);
    }

    #[Test]
    public function diffCoveredMsiFloorIsOverridableForEquivalentMutants(): void
    {
        $args = new InfectionArguments()->diff($this->options(diffBase: 'origin/main', diffCoveredMsi: 95), '/c', '/cfg/infection.json', '/p/src/Changed.php');

        self::assertContains('--min-covered-msi=95', $args);
        self::assertNotContains('--min-covered-msi=100', $args, 'the overridden floor must REPLACE the default 100');
    }

    #[Test]
    public function onlyCoveredIsTheFirstFlagInEitherLane(): void
    {
        $full = new InfectionArguments()->full($this->options(onlyCovered: true), '/c', '/cfg/infection.json');

        self::assertSame('--only-covered', $full[0]);
        self::assertSame('--coverage=/c', $full[1]);
    }

    private function options(?string $diffBase = null, int $diffCoveredMsi = 100, bool $onlyCovered = false): InfectionOptionsDto
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
