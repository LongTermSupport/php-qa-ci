<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\assets\phpunitAnnotations\projectMissingMedium\tests\Medium;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 *
 * @small
 */
final class SomethingTest extends TestCase
{
    /**
     * @modium
     * @test
     */
    public function itDoesSomething(): void
    {
    }
}
