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
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Helper::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class HelperTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testItCanGetComposerJsonDecode(): void
    {
        $actual = Helper::getComposerJsonDecoded();
        self::assertNotEmpty($actual);
    }

    /**
     * @throws Exception
     */
    public function testItWillThrowExceptionForInvalidComposerJson(): void
    {
        $this->expectException(JsonException::class);
        Helper::getComposerJsonDecoded(__DIR__ . '/../assets/helper/invalid.composer.json');
    }

    /**
     * @throws Exception
     */
    public function testGetProjectRoot(): void
    {
        $expected = \Safe\realpath(__DIR__ . '/../../');
        $actual   = Helper::getProjectRootDirectory();
        self::assertSame($expected, $actual);
    }
}
