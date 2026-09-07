<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\ProjectRecord\Dto;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\JustificationFindingDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JustificationFindingDto::class)]
#[Small]
final class JustificationFindingDtoTest extends TestCase
{
    #[Test]
    public function itCarriesWhereAndWhy(): void
    {
        $finding = new JustificationFindingDto(line: 12, entry: 'identifier: x', fault: 'no justification');

        self::assertSame(12, $finding->line);
        self::assertSame('identifier: x', $finding->entry);
        self::assertSame('no justification', $finding->fault);
    }
}
