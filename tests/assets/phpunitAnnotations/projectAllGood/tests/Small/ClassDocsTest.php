<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\assets\phpunitAnnotations\projectAllGood\tests\Small;

use PHPUnit\Framework\TestCase;

/**
 * Class ClassDocsTest.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\Small]
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class ClassDocsTest extends TestCase
{
}
