<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestCapabilities;
use LTS\PHPQA\Tests\Support\ParatestFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParatestCapabilities::class)]
#[Small]
final class ParatestCapabilitiesTest extends TestCase
{
    private const string UNREPORTED = 'ParaTest (version not reported)';

    private const string FIVE = '5.0.4';

    private const string SIX = '6.11.1';

    private const string SEVEN = '7.27.4';

    private const string FAIL_ON_RISKY = '--fail-on-risky';

    private const string FAIL_ON_WARNING = '--fail-on-warning';

    private const string STRICT_GLOBAL_STATE = '--strict-global-state';

    private const string NO_COVERAGE = '--no-coverage';

    private const string LOG_JUNIT = '--log-junit';

    private const string ENFORCE_TIME_LIMIT = '--enforce-time-limit';

    private const string DISPLAY_ERRORS = '--display-errors';

    private const string DISPLAY_WARNINGS = '--display-warnings';

    private const string CONFIGURATION = '--configuration';

    #[Test]
    public function paratestFiveAcceptsItsOwnOptionsAndNoneOfThePhpunitStrictnessFlags(): void
    {
        $capabilities = $this->capabilities(self::FIVE);

        self::assertSame(
            [self::NO_COVERAGE, self::FAIL_ON_RISKY, self::FAIL_ON_WARNING, self::STRICT_GLOBAL_STATE, self::ENFORCE_TIME_LIMIT, self::DISPLAY_ERRORS],
            $capabilities->lacking(
                self::LOG_JUNIT,
                self::NO_COVERAGE,
                self::FAIL_ON_RISKY,
                self::FAIL_ON_WARNING,
                self::STRICT_GLOBAL_STATE,
                self::ENFORCE_TIME_LIMIT,
                self::DISPLAY_ERRORS,
            ),
        );
    }

    #[Test]
    public function paratestFiveReportsNoVersionAndIsNamedAsSuch(): void
    {
        self::assertSame(self::UNREPORTED, $this->capabilities(self::FIVE)->label());
    }

    #[Test]
    public function paratestSixAcceptsTheLogAndCoverageFlagsButNotTheStrictnessFlags(): void
    {
        $capabilities = $this->capabilities(self::SIX);

        self::assertSame(
            [self::FAIL_ON_RISKY, self::STRICT_GLOBAL_STATE, self::DISPLAY_WARNINGS],
            $capabilities->lacking('-c', self::LOG_JUNIT, self::NO_COVERAGE, self::FAIL_ON_RISKY, self::STRICT_GLOBAL_STATE, self::DISPLAY_WARNINGS),
        );
        self::assertSame('ParaTest ' . self::SIX, $capabilities->label());
    }

    #[Test]
    public function paratestSevenForwardsEveryFlagTheLanePasses(): void
    {
        $capabilities = $this->capabilities(self::SEVEN);

        self::assertSame([], $capabilities->lacking(
            '-c',
            self::LOG_JUNIT,
            self::STRICT_GLOBAL_STATE,
            self::FAIL_ON_RISKY,
            self::FAIL_ON_WARNING,
            '--colors',
            '--display-incomplete',
            '--display-skipped',
            '--display-deprecations',
            '--display-phpunit-deprecations',
            self::DISPLAY_ERRORS,
            '--display-notices',
            '--display-phpunit-notices',
            self::DISPLAY_WARNINGS,
            '--order-by',
            '--stop-on-failure',
            '--stop-on-error',
            '--stop-on-defect',
            '--stop-on-warning',
            self::NO_COVERAGE,
            self::ENFORCE_TIME_LIMIT,
        ));
        self::assertSame('ParaTest ' . self::SEVEN, $capabilities->label());
    }

    #[Test]
    public function aFlagNamedOnlyInADescriptionIsNotAnOption(): void
    {
        self::assertSame(['--prepend'], $this->capabilities(self::FIVE)->lacking('--prepend'));
    }

    #[Test]
    public function aShortOptionIsKnownByItsShortName(): void
    {
        $capabilities = $this->capabilities(self::FIVE);

        self::assertSame([], $capabilities->lacking('-c', self::CONFIGURATION));
        self::assertSame(['-x'], $capabilities->lacking('-x'));
    }

    #[Test]
    public function anOptionMustBeAWholeName(): void
    {
        $capabilities = ParatestCapabilities::fromProbes("  --fail-on-risky-thing  x\n  --log-junit=F  y\n", '');

        self::assertSame([self::FAIL_ON_RISKY], $capabilities->lacking(self::FAIL_ON_RISKY, self::LOG_JUNIT));
    }

    #[Test]
    public function anOptionLineWithColourCodesAndNoIndentIsRead(): void
    {
        $capabilities = ParatestCapabilities::fromProbes("\x1B[32m-c, --configuration=C\x1B[39m  x\n\x1B[32m    --no-coverage\x1B[39m  y\n", '');

        self::assertSame([], $capabilities->lacking('-c', self::CONFIGURATION, self::NO_COVERAGE));
    }

    #[Test]
    public function anEmptyHelpAcceptsNothingAndKeepsTheOrderGiven(): void
    {
        $capabilities = ParatestCapabilities::fromProbes('', '');

        self::assertSame(['--b', '--a'], $capabilities->lacking('--b', '--a'));
        self::assertSame(self::UNREPORTED, $capabilities->label());
    }

    #[Test]
    public function aVersionWithColourCodesAroundItIsNamedWithoutThem(): void
    {
        $capabilities = ParatestCapabilities::fromProbes('', "ParaTest \x1B[32mv8.0.1\x1B[39m\n");

        self::assertSame('ParaTest 8.0.1', $capabilities->label());
    }

    private function capabilities(string $version): ParatestCapabilities
    {
        return ParatestCapabilities::fromProbes(ParatestFixture::help($version), ParatestFixture::version($version));
    }
}
