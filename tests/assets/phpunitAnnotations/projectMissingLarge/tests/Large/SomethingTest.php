<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\assets\phpunitAnnotations\projectMissingSmall\tests\Small;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class SomethingTest extends TestCase
{
    /**
     * @largo
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesSomething(): void
    {
    }
}
