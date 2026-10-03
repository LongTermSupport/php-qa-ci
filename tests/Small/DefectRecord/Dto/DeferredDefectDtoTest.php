<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\DefectRecord\Dto;

use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DeferredDefectDto::class)]
#[Small]
final class DeferredDefectDtoTest extends TestCase
{
    private const string OWNER = 'Owner';

    #[Test]
    public function itCarriesTheDefectItsClassWhereItWasFoundAndWhoDeferredIt(): void
    {
        $entry = new DeferredDefectDto('The key omits the locale.', 'A partial cache key.', 'src/Key.php', self::OWNER);

        self::assertSame('The key omits the locale.', $entry->defect);
        self::assertSame('A partial cache key.', $entry->class);
        self::assertSame('src/Key.php', $entry->found);
        self::assertSame(self::OWNER, $entry->deferredBy);
    }

    #[Test]
    public function theClassIsAbsentWhereNoneIsApparentYet(): void
    {
        self::assertNull(new DeferredDefectDto('x', null, 'src/A.php', self::OWNER)->class);
    }
}
