<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;
use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestCapabilities;
use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestIncompatibleException;
use LTS\PHPQA\Pipeline\Lane\Phpunit\PhpunitArguments;
use LTS\PHPQA\Tests\Support\ParatestFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpunitArguments::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(PhpUnitOptionsDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ParatestCapabilities::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ParatestIncompatibleException::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ParatestFixture::class)]
#[Small]
final class PhpunitArgumentsTest extends TestCase
{
    private const string ENFORCE_TIME_LIMIT = '--enforce-time-limit';

    private const string TEST_A = '/p/tests/A.php';

    private const string TEST_B = '/p/tests/B.php';

    private const string NO_COVERAGE = '--no-coverage';

    private const array BASE = [
        '-c',
        '/cfg/phpunit.xml',
        '--strict-global-state',
        '--fail-on-risky',
        '--fail-on-warning',
        '--log-junit',
        '/var/qa/phpunit_logs/phpunit.junit.xml',
    ];

    private const array DISPLAY = [
        '--colors=always',
        '--display-incomplete',
        '--display-skipped',
        '--display-deprecations',
        '--display-phpunit-deprecations',
        '--display-errors',
        '--display-notices',
        '--display-phpunit-notices',
        '--display-warnings',
    ];

    #[Test]
    public function coverageInCiAddsNothingBeyondTheBaseAndDisplayFlags(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: true);

        self::assertSame([...self::BASE, ...self::DISPLAY], $args);
    }

    #[Test]
    public function coverageInteractivelyEnforcesTimeLimits(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: false);

        self::assertSame([...self::BASE, ...self::DISPLAY, self::ENFORCE_TIME_LIMIT], $args);
    }

    #[Test]
    public function noCoverageDisablesCoverageAndEnforcesTimeLimits(): void
    {
        $args = $this->build(coverage: false, iterative: false, ci: true);

        self::assertSame([...self::BASE, ...self::DISPLAY, self::NO_COVERAGE, self::ENFORCE_TIME_LIMIT], $args);
    }

    #[Test]
    public function iterativeModeWinsOverCoverageAndCi(): void
    {
        $args = $this->build(coverage: true, iterative: true, ci: true);

        self::assertSame([
            ...self::BASE,
            ...self::DISPLAY,
            '--order-by=depends,defects',
            '--stop-on-failure',
            '--stop-on-error',
            '--stop-on-defect',
            '--stop-on-warning',
            self::NO_COVERAGE,
            self::ENFORCE_TIME_LIMIT,
        ], $args);
    }

    #[Test]
    public function aPhpunitBelowTenGetsNoDisplayFlags(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: true, major: 9);

        self::assertSame(self::BASE, $args);
    }

    #[Test]
    public function aParatestThatAcceptsEveryFlagIsGivenTheSameArgvAsPhpunitAndNoPhpunitOption(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: true, paratest: '7.27.4');

        self::assertSame([...self::BASE, ...self::DISPLAY], $args);
    }

    #[Test]
    public function aParatestThatAcceptsEveryFlagTakesASinglePathAsItIs(): void
    {
        $args = $this->build(false, false, true, 12, '7.27.4', self::TEST_A);

        self::assertSame([...self::BASE, ...self::DISPLAY, self::NO_COVERAGE, self::ENFORCE_TIME_LIMIT, self::TEST_A], $args);
    }

    #[Test]
    public function everyModeIsCheckedAgainstTheFlagsItPasses(): void
    {
        foreach ([[true, false, true], [true, false, false], [false, false, true], [true, true, true]] as [$coverage, $iterative, $ci]) {
            $args = $this->build($coverage, $iterative, $ci, 12, '7.27.4');

            self::assertSame(self::BASE, \array_slice($args, 0, \count(self::BASE)));
        }
    }

    #[Test]
    public function aParatestWithoutTheStrictnessFlagsIsRefusedByNamingItAndEveryMissingFlag(): void
    {
        $this->expectException(ParatestIncompatibleException::class);
        $this->expectExceptionMessage(
            'the installed ParaTest 6.11.1 does not accept --strict-global-state, --fail-on-risky, --fail-on-warning,'
            . ' --display-incomplete, --display-skipped, --display-deprecations, --display-phpunit-deprecations,'
            . ' --display-errors, --display-notices, --display-phpunit-notices, --display-warnings,'
            . ' which the lane passes to it; use ParaTest 7 or later, or remove ParaTest to run with PHPUnit',
        );

        $this->build(coverage: true, iterative: false, ci: true, paratest: '6.11.1');
    }

    #[Test]
    public function paratestFiveIsRefusedEvenThoughItStillAcceptsThePhpunitOption(): void
    {
        $this->expectException(ParatestIncompatibleException::class);
        $this->expectExceptionMessage(
            'the installed ParaTest (version not reported) does not accept --strict-global-state, --fail-on-risky, --fail-on-warning,'
            . ' --no-coverage, --enforce-time-limit, which the lane passes to it;'
            . ' use ParaTest 7 or later, or remove ParaTest to run with PHPUnit',
        );

        $this->build(false, false, true, 9, '5.0.4');
    }

    #[Test]
    public function onlyTheFlagsOfTheRunAreCheckedSoAPhpunitNineRunNeedsNoDisplayFlags(): void
    {
        $this->expectException(ParatestIncompatibleException::class);
        $this->expectExceptionMessage(
            'the installed ParaTest 6.11.1 does not accept --strict-global-state, --fail-on-risky, --fail-on-warning,'
            . ' which the lane passes to it; use ParaTest 7 or later, or remove ParaTest to run with PHPUnit',
        );

        $this->build(true, false, true, 9, '6.11.1');
    }

    #[Test]
    public function aMissingFlagIsReportedBeforeSeveralPaths(): void
    {
        $this->expectException(ParatestIncompatibleException::class);
        $this->expectExceptionMessageMatches('/^the installed ParaTest 6\.11\.1 does not accept /');

        $this->build(false, false, true, 12, '6.11.1', self::TEST_A, self::TEST_B);
    }

    #[Test]
    public function severalPathsAreRefusedForParatestWithTheCount(): void
    {
        $this->expectException(ParatestIncompatibleException::class);
        $this->expectExceptionMessage('ParaTest takes one path and 2 were specified');

        $this->build(false, false, true, 12, '7.27.4', self::TEST_A, self::TEST_B);
    }

    #[Test]
    public function severalPathsAreFineForPlainPhpunit(): void
    {
        $args = $this->build(false, false, true, 12, null, self::TEST_A, self::TEST_B);

        self::assertSame([self::TEST_A, self::TEST_B], \array_slice($args, -2));
    }

    #[Test]
    public function specifiedPathsComeLast(): void
    {
        $args = $this->build(false, false, true, 12, null, '/p/tests/Unit', '/p/src/A.php');

        self::assertSame([...self::BASE, ...self::DISPLAY, self::NO_COVERAGE, self::ENFORCE_TIME_LIMIT, '/p/tests/Unit', '/p/src/A.php'], $args);
    }

    /** @return list<string> */
    private function build(bool $coverage, bool $iterative, bool $ci, int $major = 12, ?string $paratest = null, string ...$paths): array
    {
        $capabilities = null === $paratest
            ? null
            : ParatestCapabilities::fromProbes(ParatestFixture::help($paratest), ParatestFixture::version($paratest));

        return new PhpunitArguments()->build(
            new PhpUnitOptionsDto(coverage: $coverage, quickTests: false, iterativeMode: $iterative),
            $ci,
            $major,
            '/cfg/phpunit.xml',
            '/var/qa/phpunit_logs/phpunit.junit.xml',
            $capabilities,
            ...$paths,
        );
    }
}
