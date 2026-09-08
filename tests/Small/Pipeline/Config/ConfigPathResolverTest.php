<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ConfigPathResolver::class)]
#[Small]
final class ConfigPathResolverTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/pipeline/configPath';

    #[Test]
    public function theProjectOverrideWinsWhenPresent(): void
    {
        $resolver = new ConfigPathResolver(self::FIXTURE . '/qaConfig', self::FIXTURE . '/configDefaults', PlatformEnum::Symfony);

        self::assertSame(self::FIXTURE . '/qaConfig/overridden.neon', $resolver->resolve('overridden.neon'));
        self::assertTrue($resolver->isProjectOverride('overridden.neon'));
    }

    #[Test]
    public function thePlatformDefaultWinsOverGeneric(): void
    {
        $resolver = new ConfigPathResolver(self::FIXTURE . '/qaConfig', self::FIXTURE . '/configDefaults', PlatformEnum::Symfony);

        self::assertSame(self::FIXTURE . '/configDefaults/symfony/platform.neon', $resolver->resolve('platform.neon'));
        self::assertFalse($resolver->isProjectOverride('platform.neon'));
    }

    #[Test]
    public function genericIsTheFallbackAndIsReturnedEvenWhenAbsent(): void
    {
        $resolver = new ConfigPathResolver(self::FIXTURE . '/qaConfig', self::FIXTURE . '/configDefaults', PlatformEnum::Generic);

        self::assertSame(self::FIXTURE . '/configDefaults/generic/generic.neon', $resolver->resolve('generic.neon'));
        self::assertSame(self::FIXTURE . '/configDefaults/generic/nope.neon', $resolver->resolve('nope.neon'));
    }

    #[Test]
    public function aGenericPlatformNeverReadsTheSymfonyRung(): void
    {
        $resolver = new ConfigPathResolver(self::FIXTURE . '/qaConfig', self::FIXTURE . '/configDefaults', PlatformEnum::Generic);

        self::assertSame(self::FIXTURE . '/configDefaults/generic/platform.neon', $resolver->resolve('platform.neon'));
    }
}
