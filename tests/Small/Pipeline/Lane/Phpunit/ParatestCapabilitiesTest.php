<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestCapabilities;
use LTS\PHPQA\Tests\Support\ParatestFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParatestCapabilities::class)]
#[UsesClass(ParatestFixture::class)]
#[Small]
final class ParatestCapabilitiesTest extends TestCase
{
    private const string UNREPORTED = 'ParaTest (version not reported)';

    private const string FAIL_ON_RISKY = '--fail-on-risky';

    private const string STRICT_GLOBAL_STATE = '--strict-global-state';

    private const string CONFIGURATION = '--configuration';

    #[Test]
    public function paratestFiveAcceptsItsOwnOptionsAndNoneOfThePhpunitStrictnessFlags(): void
    {
        $capabilities = $this->capabilities('5.0.4');

        self::assertSame(['--no-coverage', self::FAIL_ON_RISKY, '--fail-on-warning', self::STRICT_GLOBAL_STATE, '--enforce-time-limit', '--display-errors'], $capabilities->lacking([
            '--log-junit',
            '--no-coverage',
            self::FAIL_ON_RISKY,
            '--fail-on-warning',
            self::STRICT_GLOBAL_STATE,
            '--enforce-time-limit',
            '--display-errors',
        ]));
    }

    #[Test]
    public function paratestFiveReportsNoVersionAndIsNamedAsSuch(): void
    {
        self::assertSame(self::UNREPORTED, $this->capabilities('5.0.4')->label());
    }

    #[Test]
    public function paratestSixAcceptsTheLogAndCoverageFlagsButNotTheStrictnessFlags(): void
    {
        $capabilities = $this->capabilities('6.11.1');

        self::assertSame(
            [self::FAIL_ON_RISKY, self::STRICT_GLOBAL_STATE, '--display-warnings'],
            $capabilities->lacking(['-c', '--log-junit', '--no-coverage', self::FAIL_ON_RISKY, self::STRICT_GLOBAL_STATE, '--display-warnings']),
        );
        self::assertSame('ParaTest 6.11.1', $capabilities->label());
    }

    #[Test]
    public function paratestSevenForwardsEveryFlagTheLanePasses(): void
    {
        $capabilities = $this->capabilities('7.27.4');

        self::assertSame([], $capabilities->lacking([
            '-c',
            '--log-junit',
            self::STRICT_GLOBAL_STATE,
            self::FAIL_ON_RISKY,
            '--fail-on-warning',
            '--colors',
            '--display-incomplete',
            '--display-skipped',
            '--display-deprecations',
            '--display-phpunit-deprecations',
            '--display-errors',
            '--display-notices',
            '--display-phpunit-notices',
            '--display-warnings',
            '--order-by',
            '--stop-on-failure',
            '--stop-on-error',
            '--stop-on-defect',
            '--stop-on-warning',
            '--no-coverage',
            '--enforce-time-limit',
        ]));
        self::assertSame('ParaTest 7.27.4', $capabilities->label());
    }

    #[Test]
    public function aFlagNamedOnlyInADescriptionIsNotAnOption(): void
    {
        self::assertSame(['--prepend'], $this->capabilities('5.0.4')->lacking(['--prepend']));
    }

    #[Test]
    public function aShortOptionIsKnownByItsShortName(): void
    {
        $capabilities = $this->capabilities('5.0.4');

        self::assertSame([], $capabilities->lacking(['-c', self::CONFIGURATION]));
        self::assertSame(['-x'], $capabilities->lacking(['-x']));
    }

    #[Test]
    public function anOptionMustBeAWholeName(): void
    {
        $capabilities = ParatestCapabilities::fromProbes("  --fail-on-risky-thing  x\n  --log-junit=F  y\n", '');

        self::assertSame([self::FAIL_ON_RISKY], $capabilities->lacking([self::FAIL_ON_RISKY, '--log-junit']));
    }

    #[Test]
    public function anOptionLineWithColourCodesAndNoIndentIsRead(): void
    {
        $capabilities = ParatestCapabilities::fromProbes("\x1B[32m-c, --configuration=C\x1B[39m  x\n\x1B[32m    --no-coverage\x1B[39m  y\n", '');

        self::assertSame([], $capabilities->lacking(['-c', self::CONFIGURATION, '--no-coverage']));
    }

    #[Test]
    public function anEmptyHelpAcceptsNothingAndKeepsTheOrderGiven(): void
    {
        $capabilities = ParatestCapabilities::fromProbes('', '');

        self::assertSame(['--b', '--a'], $capabilities->lacking(['--b', '--a']));
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
