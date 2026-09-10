<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\PlatformDetector;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PlatformDetector::class)]
#[Small]
final class PlatformDetectorTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/pipeline';

    #[Test]
    public function aSymfonyLockMeansSymfony(): void
    {
        self::assertSame(PlatformEnum::Symfony, new PlatformDetector()->detect(self::FIXTURE . '/symfonyProject'));
    }

    #[Test]
    public function anythingElseIsGeneric(): void
    {
        self::assertSame(PlatformEnum::Generic, new PlatformDetector()->detect(self::FIXTURE . '/project'));
        self::assertSame(PlatformEnum::Generic, new PlatformDetector()->detect(self::FIXTURE . '/nope'));
    }
}
