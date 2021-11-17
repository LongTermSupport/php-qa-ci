<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use Exception;
use JsonException;
use LTS\PHPQA\Helper;
use PHPUnit\Framework\TestCase;

/**
 * Class HelperTest.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @coversDefaultClass \LTS\PHPQA\Helper
 *
 * @internal
 *
 * @small
 */
final class HelperTest extends TestCase
{
    /**
     * @throws Exception
     * @covers ::getComposerJsonDecoded()
     * @covers ::getProjectRootDirectory()
     */
    public function testItCanGetComposerJsonDecode(): void
    {
        $actual = Helper::getComposerJsonDecoded();
        self::assertNotEmpty($actual);
    }

    /**
     * @throws Exception
     * @covers ::getComposerJsonDecoded()
     */
    public function testItWillThrowExceptionForInvalidComposerJson(): void
    {
        $this->expectException(JsonException::class);
        Helper::getComposerJsonDecoded(__DIR__ . '/../assets/helper/invalid.composer.json');
    }

    /**
     * @throws Exception
     * @covers ::getProjectRootDirectory()
     */
    public function testGetProjectRoot(): void
    {
        $expected = realpath(__DIR__ . '/../../');
        $actual   = Helper::getProjectRootDirectory();
        self::assertSame($expected, $actual);
    }
}
