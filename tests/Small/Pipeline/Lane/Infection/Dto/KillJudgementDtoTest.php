<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection\Dto;

use LogicException;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\KillJudgementDto;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\VacuousKillDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Whether the suite started at all, and Infection's two scores with the kills
 * no test made counted as not detected, worked out from its counts as
 * Infection works them out (issue #125).
 *
 * @internal
 */
#[CoversClass(KillJudgementDto::class)]
#[UsesClass(VacuousKillDto::class)]
#[Small]
final class KillJudgementDtoTest extends TestCase
{
    #[Test]
    public function theSuiteNeverStartedWhenEveryKillIsVacuous(): void
    {
        self::assertTrue(new KillJudgementDto(2, [$this->vacuous(), $this->vacuous()])->suiteNeverStarted());
        self::assertFalse(new KillJudgementDto(3, [$this->vacuous(), $this->vacuous()])->suiteNeverStarted());
        self::assertFalse(new KillJudgementDto(0, [])->suiteNeverStarted(), 'nothing killed is nothing to judge');
    }

    /** The fix-118 run: 61 detected of 70 tested, one of them not covered, 7 of the kills vacuous. */
    #[Test]
    public function theScoresWithoutTheVacuousKillsAreWorkedOutFromTheCounts(): void
    {
        $judgement = new KillJudgementDto(61, array_fill(0, 7, $this->vacuous()), detected: 61, tested: 70, testedCovered: 69);

        self::assertSame(77.14, $judgement->msiWithoutVacuous(), '54 of 70');
        self::assertSame(78.26, $judgement->coveredMsiWithoutVacuous(), '54 of 69; 88.41 less 7/69 would give 78.27');
    }

    /** With no mutant tested there is no score, and Infection reports it as 0. */
    #[Test]
    public function aScoreOverNoMutantsIsZero(): void
    {
        $judgement = new KillJudgementDto(0, [], detected: 0, tested: 0, testedCovered: 0);

        self::assertSame(0.0, $judgement->msiWithoutVacuous());
        self::assertSame(0.0, $judgement->coveredMsiWithoutVacuous());
    }

    /** Infection fails a score below its floor, so a score equal to the floor meets it, and 0 is no floor. */
    #[Test]
    public function theFloorsHoldWhenBothScoresEqualOrExceedThem(): void
    {
        $judgement = new KillJudgementDto(4, [$this->vacuous()], detected: 4, tested: 4, testedCovered: 3);

        self::assertSame(75.0, $judgement->msiWithoutVacuous());
        self::assertSame(100.0, $judgement->coveredMsiWithoutVacuous());
        self::assertTrue($judgement->floorsHoldWithoutVacuous(75, 100));
        self::assertTrue($judgement->floorsHoldWithoutVacuous(0, 0));
        self::assertFalse($judgement->floorsHoldWithoutVacuous(76, 100), 'the MSI, 75, is below its floor');
        self::assertFalse(new KillJudgementDto(4, [$this->vacuous()], detected: 4, tested: 4, testedCovered: 4)->floorsHoldWithoutVacuous(75, 76), 'the covered-code MSI, 75, is below its floor');
    }

    /** Without Infection's counts there are no scores to work out, which is a caller's error, not a 0. */
    #[Test]
    public function theScoresNeedTheCounts(): void
    {
        $this->expectException(LogicException::class);

        new KillJudgementDto(2, [$this->vacuous()])->msiWithoutVacuous();
    }

    /** @return iterable<string, array{KillJudgementDto}> */
    public static function judgementsMissingACount(): iterable
    {
        $vacuous = [new VacuousKillDto('src/Foo.php:7 Plus', 'No tests executed!')];

        yield 'no tested count' => [new KillJudgementDto(2, $vacuous, detected: 2, testedCovered: 2)];

        yield 'no covered count' => [new KillJudgementDto(2, $vacuous, detected: 2, tested: 2)];
    }

    /** Each score needs every count: a missing one is not a 0. */
    #[Test]
    #[DataProvider('judgementsMissingACount')]
    public function eachCountIsNeeded(KillJudgementDto $judgement): void
    {
        $this->expectException(LogicException::class);

        $judgement->floorsHoldWithoutVacuous(0, 0);
    }

    private function vacuous(): VacuousKillDto
    {
        return new VacuousKillDto('src/Foo.php:7 Plus', 'No tests executed!');
    }
}
