<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\assets\phpunitAnnotations\projectAllGood\tests\Medium;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class SomethingTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Medium]
    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesSomething(): void
    {
    }
}
