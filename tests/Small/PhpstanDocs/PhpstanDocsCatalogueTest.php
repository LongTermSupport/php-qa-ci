<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PhpstanDocs;

use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpstanDocsCatalogue::class)]
#[Small]
final class PhpstanDocsCatalogueTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../assets/phpstanDocs/library';

    #[Test]
    public function anIdentifierResolvesToItsPage(): void
    {
        self::assertSame(
            \Safe\realpath(self::FIXTURE . '/vendor-docs/phpstan/errors/argument.type.md'),
            new PhpstanDocsCatalogue(self::FIXTURE)->pagePath('argument.type'),
        );
    }

    #[Test]
    public function anIdentifierWithNoPageResolvesToNothing(): void
    {
        self::assertNull(new PhpstanDocsCatalogue(self::FIXTURE)->pagePath('method.notFound'));
    }

    #[Test]
    public function aStringThatIsNotAnIdentifierNamesNoPath(): void
    {
        $catalogue = new PhpstanDocsCatalogue(self::FIXTURE);

        self::assertNull($catalogue->pagePath('../version'));
        self::assertNull($catalogue->pagePath('argument/type'));
        self::assertNull($catalogue->pagePath('argument'));
    }

    #[Test]
    public function theCarriedAndInstalledVersionsAreRead(): void
    {
        $catalogue = new PhpstanDocsCatalogue(self::FIXTURE);

        self::assertSame('2.2.16', $catalogue->carriedVersion());
        self::assertSame('phpstan/phpstan 2.3.x@0123456789ab', $catalogue->carriedSource());
        self::assertSame('2.2.16', $catalogue->installedPhpstanVersion());
    }

    #[Test]
    public function aLibraryWithNoCatalogueAndNoPinHasNoVersions(): void
    {
        $catalogue = new PhpstanDocsCatalogue(__DIR__);

        self::assertNull($catalogue->carriedVersion());
        self::assertNull($catalogue->carriedSource());
        self::assertNull($catalogue->installedPhpstanVersion());
    }
}
