<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\assets\phpunitAnnotations\projectAllGood\tests\Small;

use Exception;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('somethingThings')]
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class SomethingTest extends TestCase
{
    /**
     * @throws Exception
     */
    #[\PHPUnit\Framework\Attributes\Large]
    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesSomething(): void
    {
    }
}
