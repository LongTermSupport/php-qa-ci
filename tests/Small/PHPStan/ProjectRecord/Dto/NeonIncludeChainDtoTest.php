<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\ProjectRecord\Dto;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NeonIncludeChainDto::class)]
#[UsesClass(NeonRecordFileDto::class)]
#[Small]
final class NeonIncludeChainDtoTest extends TestCase
{
    #[Test]
    public function itCarriesTheFilesReadAndWhatStoppedTheRest(): void
    {
        $file  = new NeonRecordFileDto(path: '/p/a.neon', display: 'a.neon', neon: '', declaredEntries: 0);
        $chain = new NeonIncludeChainDto([$file], ['a.neon includes gone.neon, which does not exist']);

        self::assertSame([$file], $chain->files);
        self::assertSame(['a.neon includes gone.neon, which does not exist'], $chain->problems);
    }
}
