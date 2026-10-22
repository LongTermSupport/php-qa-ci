<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Tool;

use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
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
#[\PHPUnit\Framework\Attributes\UsesClass(ChildExit::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ProcessResultDto::class)]
final class ToolResultDtoTest extends TestCase
{
    private const string PHPSTAN = 'PHPStan';

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
        self::assertSame(ToolOutcomeEnum::Passed, ToolResultDto::fromExitCode(0, self::PHPSTAN)->outcome);

        $anyNonZeroFails = ToolResultDto::fromExitCode(3, 'PHP Lint');
        self::assertSame(ToolOutcomeEnum::Failed, $anyNonZeroFails->outcome);
        self::assertSame('PHP Lint failed (exit 3)', $anyNonZeroFails->summary);

        self::assertSame(ToolOutcomeEnum::Failed, ToolResultDto::fromExitCode(1, self::PHPSTAN, 1)->outcome);
        $crash = ToolResultDto::fromExitCode(255, self::PHPSTAN, 1);
        self::assertSame(ToolOutcomeEnum::Crashed, $crash->outcome);
        self::assertSame('PHPStan crashed (exit 255)', $crash->summary);
        self::assertSame('PHPStan crashed (exit 143, killed by signal 15 (SIGTERM))', ToolResultDto::fromExitCode(143, self::PHPSTAN, 1)->summary);
    }

    /** A process result says how the child ended: a code it exited with itself is not called a kill. */
    #[Test]
    public function processResultsMapToOutcomesDescribedAsTheyEnded(): void
    {
        self::assertSame(ToolOutcomeEnum::Passed, ToolResultDto::fromProcessResult(new ProcessResultDto(0, '', ''), self::PHPSTAN)->outcome);

        $failed = ToolResultDto::fromProcessResult(new ProcessResultDto(3, '', ''), 'PHP Lint');
        self::assertSame(ToolOutcomeEnum::Failed, $failed->outcome);
        self::assertSame('PHP Lint failed (exit 3)', $failed->summary);

        $killed = ToolResultDto::fromProcessResult(new ProcessResultDto(143, '', '', \SIGTERM), self::PHPSTAN, 1);
        self::assertSame(ToolOutcomeEnum::Crashed, $killed->outcome);
        self::assertSame('PHPStan crashed (exit 143, killed by signal 15 (SIGTERM))', $killed->summary);

        $relayed = ToolResultDto::fromProcessResult(new ProcessResultDto(139, '', ''), self::PHPSTAN, 1);
        self::assertSame(ToolOutcomeEnum::Crashed, $relayed->outcome);
        self::assertSame('PHPStan crashed (exit 139' . ChildExit::exitedItself(139) . ')', $relayed->summary);
    }
}
