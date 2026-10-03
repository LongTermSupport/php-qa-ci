<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\DefectRecord\Dto;

use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NoPatternConclusionDto::class)]
#[Small]
final class NoPatternConclusionDtoTest extends TestCase
{
    #[Test]
    public function itCarriesTheConclusionAndTheTechniquesTried(): void
    {
        $entry = new NoPatternConclusionDto('Rounded twice.', 'src/Total.php', 'No pattern exists.', ['a text search', 'reading every caller']);

        self::assertSame('Rounded twice.', $entry->defect);
        self::assertSame('src/Total.php', $entry->found);
        self::assertSame('No pattern exists.', $entry->conclusion);
        self::assertSame(['a text search', 'reading every caller'], $entry->techniques);
    }
}
