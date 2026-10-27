<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;
use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestCapabilities;
use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestIncompatibleException;
use LTS\PHPQA\Pipeline\Lane\Phpunit\PhpunitArguments;
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
#[Small]
final class PhpunitArgumentsTest extends TestCase
{
    private const string ENFORCE_TIME_LIMIT = '--enforce-time-limit';

    private const string PHPUNIT_BIN = '/p/vendor/bin/phpunit';

    private const string TEST_A = '/p/tests/A.php';

    private const string TEST_B = '/p/tests/B.php';

    private const string LEGACY = '--phpunit=PHPUNIT The PHPUnit binary to execute.';

    private const string CURRENT = '--fail-on-risky @see PHPUnit guide';

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
    public function aParatestWithAPhpunitOptionIsHandedThePhpunitBinaryFirst(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: true, paratestHelp: self::LEGACY);

        self::assertSame(['--phpunit', self::PHPUNIT_BIN, ...self::BASE, ...self::DISPLAY], $args);
    }

    #[Test]
    public function aCurrentParatestIsNeverHandedAPhpunitOption(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: true, paratestHelp: self::CURRENT);

        self::assertSame([...self::BASE, ...self::DISPLAY], $args);
    }

    #[Test]
    public function aCurrentParatestTakesASinglePathAsItIs(): void
    {
        $args = $this->build(false, false, true, 12, self::CURRENT, self::TEST_A);

        self::assertSame([...self::BASE, ...self::DISPLAY, self::NO_COVERAGE, self::ENFORCE_TIME_LIMIT, self::TEST_A], $args);
    }

    #[Test]
    public function aLegacyParatestTakesASinglePathAfterTheFlags(): void
    {
        $args = $this->build(false, false, true, 9, self::LEGACY, self::TEST_A);

        self::assertSame(['--phpunit', self::PHPUNIT_BIN, ...self::BASE, self::NO_COVERAGE, self::ENFORCE_TIME_LIMIT, self::TEST_A], $args);
    }

    #[Test]
    public function aParatestThatCannotTakeTheArgumentsIsRefusedByName(): void
    {
        $this->expectException(ParatestIncompatibleException::class);
        $this->expectExceptionMessageIsOrContains('accepts neither --phpunit nor the PHPUnit strictness flags');

        $this->build(coverage: true, iterative: false, ci: true, paratestHelp: '--stop-on-failure');
    }

    #[Test]
    public function severalPathsAreRefusedForParatestWithTheCount(): void
    {
        $this->expectException(ParatestIncompatibleException::class);
        $this->expectExceptionMessageIsOrContains('ParaTest takes one path and 2 were specified');

        $this->build(false, false, true, 12, self::CURRENT, self::TEST_A, self::TEST_B);
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
    private function build(bool $coverage, bool $iterative, bool $ci, int $major = 12, ?string $paratestHelp = null, string ...$paths): array
    {
        $paratest = null === $paratestHelp ? null : ParatestCapabilities::fromHelp($paratestHelp);

        return new PhpunitArguments()->build(
            new PhpUnitOptionsDto(coverage: $coverage, quickTests: false, iterativeMode: $iterative),
            $ci,
            $major,
            '/cfg/phpunit.xml',
            '/var/qa/phpunit_logs/phpunit.junit.xml',
            $paratest,
            self::PHPUNIT_BIN,
            ...$paths,
        );
    }
}
