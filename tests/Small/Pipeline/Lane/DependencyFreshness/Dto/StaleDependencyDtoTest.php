<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness\Dto;

use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StaleDependencyDto::class)]
#[Small]
final class StaleDependencyDtoTest extends TestCase
{
    #[Test]
    public function itDescribesItselfWithBothVersionsAsComposerPrintedThem(): void
    {
        self::assertSame(
            'lts/php-qa-ci (locked dev-php8.5 93f1ae4, available dev-php8.5 606a9f6)',
            new StaleDependencyDto('lts/php-qa-ci', 'dev-php8.5 93f1ae4', 'dev-php8.5 606a9f6')->describe(),
        );
    }
}
