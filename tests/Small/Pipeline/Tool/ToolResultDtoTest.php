<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolResultDto::class)]
#[CoversClass(ToolOutcomeEnum::class)]
#[Small]
final class ToolResultDtoTest extends TestCase
{
    #[Test]
    public function passedAndSkippedAreSuccessesFailedAndCrashedAreNot(): void
    {
        self::assertTrue(ToolResultDto::passed()->isSuccess());
        self::assertTrue(ToolResultDto::skipped('opted out')->isSuccess());
        self::assertFalse(ToolResultDto::failed('x')->isSuccess());
        self::assertFalse(ToolResultDto::crashed('x')->isSuccess());
    }

    #[Test]
    public function onlyAFailureIsRetryable(): void
    {
        self::assertTrue(ToolOutcomeEnum::Failed->isRetryable());
        self::assertFalse(ToolOutcomeEnum::Crashed->isRetryable());
        self::assertFalse(ToolOutcomeEnum::Passed->isRetryable());
        self::assertFalse(ToolOutcomeEnum::Skipped->isRetryable());
    }

    #[Test]
    public function exitCodesMapToOutcomesWithAnOptionalFailureSet(): void
    {
        self::assertSame(ToolOutcomeEnum::Passed, ToolResultDto::fromExitCode(0, 'PHPStan')->outcome);

        $anyNonZeroFails = ToolResultDto::fromExitCode(3, 'PHP Lint');
        self::assertSame(ToolOutcomeEnum::Failed, $anyNonZeroFails->outcome);
        self::assertSame('PHP Lint failed (exit 3)', $anyNonZeroFails->summary);

        self::assertSame(ToolOutcomeEnum::Failed, ToolResultDto::fromExitCode(1, 'PHPStan', 1)->outcome);
        $crash = ToolResultDto::fromExitCode(255, 'PHPStan', 1);
        self::assertSame(ToolOutcomeEnum::Crashed, $crash->outcome);
        self::assertSame('PHPStan crashed (exit 255)', $crash->summary);
    }
}
