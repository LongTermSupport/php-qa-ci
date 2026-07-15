<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PackageType;

use LTS\PHPQA\PackageType\ExplicitPackageTypeCheck;
use LTS\PHPQA\PackageType\ExplicitPackageTypeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the thin pipeline runner around {@see ExplicitPackageTypeDetector}:
 * it maps the pure verdict to a process exit code (0 = OK, 1 = build failure) and
 * prints either a confirmation or the actionable guidance. The exhaustive
 * type-matrix lives in ExplicitPackageTypeDetectorTest; here we only assert the
 * glue (exit code + output).
 *
 * @internal
 */
#[CoversClass(ExplicitPackageTypeCheck::class)]
#[UsesClass(ExplicitPackageTypeDetector::class)]
#[Small]
final class ExplicitPackageTypeCheckTest extends TestCase
{
    #[Test]
    public function itReturnsZeroAndConfirmsForAnExplicitType(): void
    {
        $check = new ExplicitPackageTypeCheck();

        $this->expectOutputRegex('/explicit package type/');
        self::assertSame(0, $check->run(['type' => 'library']));
    }

    #[Test]
    public function itReturnsOneAndPrintsGuidanceForAnUndeclaredType(): void
    {
        $check = new ExplicitPackageTypeCheck();

        $this->expectOutputRegex('/type/');
        self::assertSame(1, $check->run(['name' => 'acme/widget']));
    }

    #[Test]
    public function itReturnsOneForABlankType(): void
    {
        $check = new ExplicitPackageTypeCheck();

        $this->expectOutputRegex('/./');
        self::assertSame(1, $check->run(['type' => '   ']));
    }

    /**
     * The confirmation line for the OK path is printed verbatim (one line plus a
     * trailing newline). Pinning it exactly proves the runner does not garble the
     * success acknowledgement — a reversed or truncated concatenation would.
     */
    #[Test]
    public function itPrintsTheExactConfirmationLineOnSuccess(): void
    {
        $check = new ExplicitPackageTypeCheck();

        $this->expectOutputString('composer.json declares an explicit package type — OK.' . \PHP_EOL);
        self::assertSame(0, $check->run(['type' => 'library']));
    }

    /**
     * On rejection the runner wraps the pure detector verdict in a titled,
     * ruled-off block. The exact framing (blank line, ERROR heading, 60-dash
     * rule, the detector message, trailing newline) is pinned so the failure
     * report stays legible and its parts stay in order.
     */
    #[Test]
    public function itPrintsTheExactRuledOffErrorBlockOnRejection(): void
    {
        $composerJson    = ['name' => 'acme/widget'];
        $detectorMessage = new ExplicitPackageTypeDetector()->check($composerJson);
        self::assertIsString($detectorMessage);

        $expected = \PHP_EOL
            . 'ERROR — composer.json package type is not declared explicitly' . \PHP_EOL
            . str_repeat('-', 60) . \PHP_EOL
            . $detectorMessage . \PHP_EOL;

        $check = new ExplicitPackageTypeCheck();
        $this->expectOutputString($expected);
        self::assertSame(1, $check->run($composerJson));
    }
}
