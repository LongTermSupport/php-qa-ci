<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Defence for the class "Composer resolves a PHPStan extension that needs a newer PHPStan than the
 * phar php-qa-ci ships" (#60).
 *
 * PHPStan runs from vendor-phar/phpstan.phar, so composer.json replaces phpstan/phpstan. Replaced
 * with `*`, Composer treats every PHPStan version as present and installs whatever extension
 * release is newest; once an extension requires a PHPStan newer than the phar, the phpstan lane
 * aborts in every consumer that updates. Replaced at the phar's exact version, Composer resolves
 * only the extension releases the phar can load. The replace must therefore move with the phar.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class PhpstanReplaceMatchesPharTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    #[Test]
    public function composerReplacesPhpstanAtTheVersionOfTheShippedPhar(): void
    {
        self::assertSame(
            $this->pharVersion(),
            $this->replacedVersion(),
            'composer.json must replace phpstan/phpstan at exactly the phive.xml installed version of phpstan.phar, or consumers resolve extensions the phar cannot load',
        );
    }

    private function pharVersion(): string
    {
        $phive = new SimpleXMLElement(\Safe\file_get_contents(self::ROOT . '/phive.xml'));
        foreach ($phive->children() as $phar) {
            if ('phpstan' === (string)$phar['name']) {
                return (string)$phar['installed'];
            }
        }

        self::fail('phive.xml lists no phpstan phar');
    }

    private function replacedVersion(): string
    {
        $composer = \Safe\json_decode(\Safe\file_get_contents(self::ROOT . '/composer.json'), true);
        self::assertIsArray($composer);
        $replace = $composer['replace'] ?? null;
        self::assertIsArray($replace, 'composer.json has no replace section');
        $version = $replace['phpstan/phpstan'] ?? null;
        self::assertIsString($version, 'composer.json does not replace phpstan/phpstan');

        return $version;
    }
}
