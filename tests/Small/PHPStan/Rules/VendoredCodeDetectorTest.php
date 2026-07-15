<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\VendoredCodeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pure vendored-vs-project decision of VendoredCodeDetector.
 *
 * The decision is anchored on the analysed project's OWN vendor directory
 * (`<currentWorkingDirectory>/vendor/`), NOT a bare `/vendor/` substring — the
 * distinction that matters when a package is developed in-place inside a
 * consumer's vendor/ (the normal way php-qa-ci is worked on).
 *
 * @internal
 */
#[CoversClass(VendoredCodeDetector::class)]
#[Small]
final class VendoredCodeDetectorTest extends TestCase
{
    public function testAProjectSourceFileIsNotVendored(): void
    {
        $detector = new VendoredCodeDetector('/home/dev/project');

        self::assertFalse($detector->isVendoredCode('/home/dev/project/src/Service.php'));
    }

    public function testAFileUnderTheProjectsOwnVendorDirIsVendored(): void
    {
        $detector = new VendoredCodeDetector('/home/dev/project');

        self::assertTrue($detector->isVendoredCode('/home/dev/project/vendor/acme/lib/src/Thing.php'));
    }

    public function testAProjectFileWhosePathContainsVendorButIsNotUnderTheProjectVendorIsNotVendored(): void
    {
        // The project ROOT itself lives under a /vendor/ path (a package being
        // developed in-place inside a consumer's vendor/). Its own src files
        // therefore contain '/vendor/' but are NOT under '<cwd>/vendor/', so
        // they are project code — a bare substring test would wrongly skip them.
        $detector = new VendoredCodeDetector('/consumer/vendor/lts/php-qa-ci');

        self::assertFalse(
            $detector->isVendoredCode('/consumer/vendor/lts/php-qa-ci/src/PHPStan/Rules/SomeRule.php'),
        );
    }

    public function testANestedDependencyOfAnInVendorPackageIsVendored(): void
    {
        // Same in-vendor package, but now the file IS one of ITS dependencies,
        // under its own nested vendor/ — that is genuinely third-party.
        $detector = new VendoredCodeDetector('/consumer/vendor/lts/php-qa-ci');

        self::assertTrue(
            $detector->isVendoredCode('/consumer/vendor/lts/php-qa-ci/vendor/phpunit/phpunit/src/Framework/TestCase.php'),
        );
    }

    public function testATrailingSlashOnTheWorkingDirectoryIsHandled(): void
    {
        $detector = new VendoredCodeDetector('/home/dev/project/');

        self::assertTrue($detector->isVendoredCode('/home/dev/project/vendor/acme/lib/Thing.php'));
        self::assertFalse($detector->isVendoredCode('/home/dev/project/src/Thing.php'));
    }

    public function testANullFileNameIsTreatedAsVendored(): void
    {
        $detector = new VendoredCodeDetector('/home/dev/project');

        self::assertTrue($detector->isVendoredCode(null));
    }
}
