<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;
use LTS\PHPQA\Pipeline\Lane\Phpunit\PhpunitArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpunitArguments::class)]
#[Small]
final class PhpunitArgumentsTest extends TestCase
{
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

        self::assertSame([...self::BASE, ...self::DISPLAY, '--enforce-time-limit'], $args);
    }

    #[Test]
    public function noCoverageDisablesCoverageAndEnforcesTimeLimits(): void
    {
        $args = $this->build(coverage: false, iterative: false, ci: true);

        self::assertSame([...self::BASE, ...self::DISPLAY, '--no-coverage', '--enforce-time-limit'], $args);
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
            '--no-coverage',
            '--enforce-time-limit',
        ], $args);
    }

    #[Test]
    public function aPhpunitBelowTenGetsNoDisplayFlags(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: true, major: 9);

        self::assertSame(self::BASE, $args);
    }

    #[Test]
    public function paratestDelegatesToThePhpunitBinaryFirst(): void
    {
        $args = $this->build(coverage: true, iterative: false, ci: true, paratestTarget: '/p/vendor/bin/phpunit');

        self::assertSame(['--phpunit', '/p/vendor/bin/phpunit', ...self::BASE, ...self::DISPLAY], $args);
    }

    #[Test]
    public function specifiedPathsComeLast(): void
    {
        $args = $this->build(coverage: false, iterative: false, ci: true, paths: ['/p/tests/Unit', '/p/src/A.php']);

        self::assertSame([...self::BASE, ...self::DISPLAY, '--no-coverage', '--enforce-time-limit', '/p/tests/Unit', '/p/src/A.php'], $args);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function build(bool $coverage, bool $iterative, bool $ci, int $major = 12, ?string $paratestTarget = null, array $paths = []): array
    {
        return new PhpunitArguments()->build(
            new PhpUnitOptionsDto(coverage: $coverage, quickTests: false, iterativeMode: $iterative),
            $ci,
            $major,
            '/cfg/phpunit.xml',
            '/var/qa/phpunit_logs/phpunit.junit.xml',
            $paratestTarget,
            ...$paths,
        );
    }
}
