<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyVendors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who a project counts as first party: the vendors it names in
 * `extra.php-qa-ci.firstPartyVendors`, else the vendor of its own `name`,
 * else nobody (and it says so).
 *
 * @internal
 */
#[CoversClass(FirstPartyVendors::class)]
#[Small]
final class FirstPartyVendorsTest extends TestCase
{
    private const string WIDGETS = 'acme/widgets';

    private const string VENDOR = 'acme';

    private const string OWN_NAME = 'acme/app';

    private const string SETTING = 'extra.php-qa-ci.firstPartyVendors';

    #[Test]
    public function withoutTheExtraKeyTheVendorOfTheProjectsOwnNameIsFirstParty(): void
    {
        $vendors = FirstPartyVendors::fromManifest(['name' => 'Acme/App']);

        self::assertSame([self::VENDOR], $vendors->vendors);
        self::assertTrue($vendors->owns(self::WIDGETS));
        self::assertTrue($vendors->owns('ACME/Gadgets'));
        self::assertFalse($vendors->owns('psr/log'));
        self::assertFalse($vendors->owns('acmeish/widgets'), 'a vendor is matched whole, not by prefix');
        self::assertStringContainsString("the vendor of this project's own name", $vendors->describe());
    }

    #[Test]
    public function theExtraKeyReplacesTheOwnVendor(): void
    {
        $vendors = FirstPartyVendors::fromManifest([
            'name'  => self::OWN_NAME,
            'extra' => ['php-qa-ci' => ['firstPartyVendors' => ['Widgetco', 'acme-labs']]],
        ]);

        self::assertSame(['widgetco', 'acme-labs'], $vendors->vendors);
        self::assertFalse($vendors->owns(self::WIDGETS), 'naming vendors replaces the own-vendor default');
        self::assertTrue($vendors->owns('widgetco/lib'));
        self::assertStringContainsString(self::SETTING, $vendors->describe());
    }

    #[Test]
    public function anEmptyExtraListMeansNoFirstPartyVendorsAndSaysSo(): void
    {
        $vendors = FirstPartyVendors::fromManifest(['name' => self::OWN_NAME, 'extra' => ['php-qa-ci' => ['firstPartyVendors' => []]]]);

        self::assertSame([], $vendors->vendors);
        self::assertFalse($vendors->owns(self::WIDGETS));
        self::assertStringContainsString(self::SETTING . ' is empty', $vendors->describe());
    }

    #[Test]
    public function aProjectWithNeitherANameNorTheKeyHasNoFirstPartyVendorsAndSaysSo(): void
    {
        $vendors = FirstPartyVendors::fromManifest(['require' => []]);

        self::assertSame([], $vendors->vendors);
        self::assertStringContainsString('no "name"', $vendors->describe());
        self::assertStringContainsString(self::SETTING, $vendors->describe());
    }

    #[Test]
    #[DataProvider('provideAManifestWhoseKeyIsMalformed')]
    public function aMalformedKeyIsRefusedNotGuessedAt(mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(self::SETTING);

        FirstPartyVendors::fromManifest(['name' => self::OWN_NAME, 'extra' => ['php-qa-ci' => ['firstPartyVendors' => $value]]]);
    }

    /** @return Iterator<string, array{mixed}> */
    public static function provideAManifestWhoseKeyIsMalformed(): Iterator
    {
        yield 'a string' => [self::VENDOR];
        yield 'a map' => [['a' => self::VENDOR]];
        yield 'a non-string entry' => [[1]];
        yield 'an empty entry' => [['']];
        yield 'a package name, not a vendor' => [[self::WIDGETS]];
    }

    #[Test]
    public function aNameWithoutASlashHasNoVendor(): void
    {
        self::assertSame([], FirstPartyVendors::fromManifest(['name' => 'standalone'])->vendors);
    }
}
