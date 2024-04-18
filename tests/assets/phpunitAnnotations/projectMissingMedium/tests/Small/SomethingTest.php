<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\assets\phpunitAnnotations\projectMissingMedium\tests\Small;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class SomethingTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Small]
    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesSomething(): void
    {
    }
}
