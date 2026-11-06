<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyConstraintJudge;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyVerdictEnum;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Tracking;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Whether a project's constraint on a package leaves out a newer release:
 * a loose constraint that excludes it is behind, an exact pin is deliberate,
 * a branch is the other check's business.
 *
 * @internal
 */
#[CoversClass(FirstPartyConstraintJudge::class)]
#[CoversClass(FirstPartyVerdictEnum::class)]
#[UsesClass(Tracking::class)]
#[Small]
final class FirstPartyConstraintJudgeTest extends TestCase
{
    private const string CARET_TWO = '^0.2';

    private const string LOCKED = '0.2.1';

    private const string NEWEST = '0.3.0';

    private const string PIN = '0.2.3';

    private const string BRANCH_LOCK = 'dev-main abc1234';

    private const string BRANCH = 'dev-main';

    #[Test]
    #[DataProvider('provideJudgements')]
    public function aConstraintIsJudgedAgainstTheNewestRelease(string $constraint, string $locked, string $latest, FirstPartyVerdictEnum $expected): void
    {
        self::assertSame($expected, FirstPartyConstraintJudge::judge($constraint, $locked, $latest));
    }

    /** @return Iterator<string, array{string, string, string, FirstPartyVerdictEnum}> */
    public static function provideJudgements(): Iterator
    {
        yield 'a new 0.x minor outside a caret' => [self::CARET_TWO, self::LOCKED, self::NEWEST, FirstPartyVerdictEnum::Behind];
        yield 'a new major outside a caret' => ['^1.4', '1.4.0', '2.0.0', FirstPartyVerdictEnum::Behind];
        yield 'a tilde' => ['~1.2.0', '1.2.3', '1.3.0', FirstPartyVerdictEnum::Behind];
        yield 'a v-prefixed newest release' => [self::CARET_TWO, 'v0.2.1', 'v0.3.0', FirstPartyVerdictEnum::Behind];
        yield 'a stability flag is not part of the range' => ['^0.2@dev', self::LOCKED, self::NEWEST, FirstPartyVerdictEnum::Behind];
        yield 'an upper bound' => ['>=0.2 <0.3', self::LOCKED, self::NEWEST, FirstPartyVerdictEnum::Behind];
        yield 'a wildcard' => ['0.2.*', self::LOCKED, self::NEWEST, FirstPartyVerdictEnum::Behind];
        yield 'a constraint that already admits both lines' => ['^0.2 || ^0.3', self::LOCKED, self::NEWEST, FirstPartyVerdictEnum::Current];
        yield "a newer release inside the constraint is the lock check's" => [self::CARET_TWO, self::LOCKED, '0.2.5', FirstPartyVerdictEnum::Current];
        yield 'an open range' => ['>=0.2', self::LOCKED, '3.0.0', FirstPartyVerdictEnum::Current];
        yield 'any version' => ['*', self::LOCKED, '3.0.0', FirstPartyVerdictEnum::Current];
        yield 'a prerelease is not a release' => [self::CARET_TWO, self::LOCKED, '0.3.0-beta1', FirstPartyVerdictEnum::Current];
        yield 'a branch head is not a release' => [self::CARET_TWO, self::LOCKED, 'dev-master 7252ec7', FirstPartyVerdictEnum::Current];
        yield 'nothing newer than the lock' => ['^0.4', '0.4.1', self::NEWEST, FirstPartyVerdictEnum::Current];
        yield 'an exact pin' => [self::PIN, self::PIN, self::NEWEST, FirstPartyVerdictEnum::Pinned];
        yield 'an exact pin with =' => ['=' . self::PIN, self::PIN, self::NEWEST, FirstPartyVerdictEnum::Pinned];
        yield 'an exact pin with v' => ['v' . self::PIN, self::PIN, self::NEWEST, FirstPartyVerdictEnum::Pinned];
        yield 'an exact pin with a stability flag' => [self::PIN . '@stable', self::PIN, self::NEWEST, FirstPartyVerdictEnum::Pinned];
        yield 'an exact pin with a reference' => [self::PIN . '#abc123', self::PIN, self::NEWEST, FirstPartyVerdictEnum::Pinned];
        yield 'a two-part version is exact in Composer' => ['1.2', '1.2.0', '1.3.0', FirstPartyVerdictEnum::Pinned];
        yield 'a pin on the newest release' => [self::NEWEST, self::NEWEST, self::NEWEST, FirstPartyVerdictEnum::Current];
        yield 'a branch' => [self::BRANCH, self::BRANCH_LOCK, self::NEWEST, FirstPartyVerdictEnum::FollowsBranch];
        yield 'a branch with a flag' => [self::BRANCH . '@dev', self::BRANCH_LOCK, self::NEWEST, FirstPartyVerdictEnum::FollowsBranch];
        yield 'a branch with an alias' => [self::BRANCH . ' as 0.2.0', self::BRANCH_LOCK, self::NEWEST, FirstPartyVerdictEnum::FollowsBranch];
        yield 'a branch alias version' => ['0.2.x-dev', '0.2.x-dev abc1234', self::NEWEST, FirstPartyVerdictEnum::FollowsBranch];
    }

    #[Test]
    public function aConstraintComposerCannotParseIsRefusedNotGuessedAt(): void
    {
        $this->expectException(UnexpectedValueException::class);

        FirstPartyConstraintJudge::judge('^^', self::LOCKED, self::NEWEST);
    }

    #[Test]
    public function theWideningAdviceAdmitsTheNewestMinorAlone(): void
    {
        self::assertSame('^0.3', FirstPartyConstraintJudge::newestLine('v' . self::NEWEST));
        self::assertSame('^2.4', FirstPartyConstraintJudge::newestLine('2.4.1'));
    }

    #[Test]
    public function theExactPinAdviceIsTheLockedVersionWithoutItsPrefix(): void
    {
        self::assertSame(self::LOCKED, FirstPartyConstraintJudge::exactPin('v' . self::LOCKED));
        self::assertSame(self::LOCKED, FirstPartyConstraintJudge::exactPin(self::LOCKED));
    }
}
