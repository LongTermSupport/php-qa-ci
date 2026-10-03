<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\DefectRecord\Dto;

use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DefectRecordDto::class)]
#[UsesClass(DeferredDefectDto::class)]
#[UsesClass(NoPatternConclusionDto::class)]
#[Small]
final class DefectRecordDtoTest extends TestCase
{
    #[Test]
    public function itCarriesWhereTheRecordIsItsEntriesAndItsProblems(): void
    {
        $deferred  = new DeferredDefectDto('x', null, 'src/A.php', 'Owner');
        $noPattern = new NoPatternConclusionDto('y', 'src/B.php', 'None.', ['a', 'b']);
        $record    = new DefectRecordDto('/p/qaConfig/defect-record.neon', [$deferred], [$noPattern], ['a problem']);

        self::assertSame('/p/qaConfig/defect-record.neon', $record->path);
        self::assertSame([$deferred], $record->deferred);
        self::assertSame([$noPattern], $record->noPattern);
        self::assertSame(['a problem'], $record->problems);
    }

    #[Test]
    public function aProjectThatWroteNoRecordHasNoPath(): void
    {
        self::assertNull(new DefectRecordDto(null, [], [], [])->path);
    }
}
