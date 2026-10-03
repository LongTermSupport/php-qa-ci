<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\ProjectRecord\Dto;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NeonRecordFileDto::class)]
#[Small]
final class NeonRecordFileDtoTest extends TestCase
{
    #[Test]
    public function itCarriesTheFileItsTextAndItsDecodedEntryCount(): void
    {
        $file = new NeonRecordFileDto(path: '/p/qaConfig/phpstan.neon', display: 'qaConfig/phpstan.neon', neon: "parameters:\n", declaredEntries: 3);

        self::assertSame('/p/qaConfig/phpstan.neon', $file->path);
        self::assertSame('qaConfig/phpstan.neon', $file->display);
        self::assertSame("parameters:\n", $file->neon);
        self::assertSame(3, $file->declaredEntries);
    }
}
