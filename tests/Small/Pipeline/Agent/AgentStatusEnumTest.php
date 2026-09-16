<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent;

use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AgentStatusEnum::class)]
#[Small]
final class AgentStatusEnumTest extends TestCase
{
    #[Test]
    public function theWireNamesAreTheDocumentedOnes(): void
    {
        self::assertSame(
            ['clean', 'errors', 'crashed', 'lock-held'],
            array_map(static fn (AgentStatusEnum $s): string => $s->value, AgentStatusEnum::cases()),
        );
    }

    #[Test]
    public function everyStatusHasItsOwnExitCodeSoLockContentionIsNeverMistakenForFindings(): void
    {
        self::assertSame(0, AgentStatusEnum::Clean->exitCode());
        self::assertSame(1, AgentStatusEnum::Errors->exitCode());
        self::assertSame(2, AgentStatusEnum::LockHeld->exitCode());
        self::assertSame(3, AgentStatusEnum::Crashed->exitCode());

        $codes = array_map(static fn (AgentStatusEnum $s): int => $s->exitCode(), AgentStatusEnum::cases());
        self::assertSame($codes, array_unique($codes), 'a shared exit code would make two outcomes indistinguishable');
    }

    #[Test]
    public function aFindingCountChoosesBetweenCleanAndErrors(): void
    {
        self::assertSame(AgentStatusEnum::Clean, AgentStatusEnum::forErrorCount(0));
        self::assertSame(AgentStatusEnum::Errors, AgentStatusEnum::forErrorCount(1));
        self::assertSame(AgentStatusEnum::Errors, AgentStatusEnum::forErrorCount(109));
    }
}
