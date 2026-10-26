<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness\Dto;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    #[DataProvider('provideAvailableVersions')]
    public function itKnowsWhetherTheAvailableVersionIsABranchHead(string $available, bool $onBranch): void
    {
        self::assertSame($onBranch, new StaleDependencyDto('lts/php-qa-ci', '85.8.0', $available)->availableIsBranchHead());
    }

    /** @return Iterator<string, array{string, bool}> */
    public static function provideAvailableVersions(): Iterator
    {
        yield 'a named dev branch' => ['dev-php8.5 606a9f6', true];
        yield 'a numbered dev branch' => ['85.x-dev 606a9f6', true];
        yield 'a release tag' => ['85.9.0', false];
        yield 'a release candidate tag' => ['85.9.0-RC1', false];
        yield 'a tag with a dev in its suffix' => ['1.0.0-developer', false];
    }
}
