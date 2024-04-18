<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\assets\phpunitAnnotations\projectMissingMedium\tests\Medium;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class SomethingTest extends TestCase
{
    /**
     * @modium
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesSomething(): void
    {
    }
}
