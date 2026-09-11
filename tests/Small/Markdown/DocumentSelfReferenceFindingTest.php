<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Markdown;

use LTS\PHPQA\Markdown\DocumentSelfReferenceFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DocumentSelfReferenceFinding::class)]
#[Small]
final class DocumentSelfReferenceFindingTest extends TestCase
{
    #[Test]
    public function itCarriesEverythingTheMessageNeeds(): void
    {
        $finding = new DocumentSelfReferenceFinding(
            'README.md',
            133,
            'copy here was maintained by hand',
            'The registry is the list. A numbered copy here was maintained by hand.',
        );

        self::assertSame('README.md', $finding->file);
        self::assertSame(133, $finding->line);
        self::assertSame('copy here was maintained by hand', $finding->matched);
        self::assertStringContainsString('numbered copy', $finding->context);
    }
}
