<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon\Dto;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequiredHandlersEditDto::class)]
#[Small]
final class RequiredHandlersEditDtoTest extends TestCase
{
    #[Test]
    public function itCarriesTheTextTheChangesAndTheBlockingSetting(): void
    {
        $dto = new RequiredHandlersEditDto('a: 1', ['Added x.enabled: true'], 'git_stash');

        self::assertSame('a: 1', $dto->yaml);
        self::assertSame(['Added x.enabled: true'], $dto->changes);
        self::assertSame('git_stash', $dto->unsupported);
    }
}
