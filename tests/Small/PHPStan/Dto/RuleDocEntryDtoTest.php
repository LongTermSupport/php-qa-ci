<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Dto;

use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RuleDocEntryDto::class)]
#[Small]
final class RuleDocEntryDtoTest extends TestCase
{
    #[Test]
    public function itCarriesWhatTheResolverFound(): void
    {
        $entry = new RuleDocEntryDto(
            identifier: 'phpqaci.example',
            ruleClass: 'ForbidExampleRule',
            summary: 'No examples',
            bundle: 'rules-default.neon',
            sourcePath: '/repo/src/PHPStan/Rules/ForbidExampleRule.php',
            docPath: null,
        );

        self::assertSame('phpqaci.example', $entry->identifier);
        self::assertSame('ForbidExampleRule', $entry->ruleClass);
        self::assertSame('No examples', $entry->summary);
        self::assertSame('rules-default.neon', $entry->bundle);
        self::assertNull($entry->docPath);
    }
}
