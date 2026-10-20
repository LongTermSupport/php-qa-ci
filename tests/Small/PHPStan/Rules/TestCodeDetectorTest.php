<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\TestCodeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TestCodeDetector::class)]
#[Small]
final class TestCodeDetectorTest extends TestCase
{
    private const string ROOT = '/home/ci/tests/my-app';

    private const string APP_SERVICE = 'App\Service';

    private TestCodeDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TestCodeDetector(self::ROOT);
    }

    #[Test]
    public function aFileUnderTheProjectsTestsDirectoryIsTestCode(): void
    {
        self::assertTrue($this->detector->isTestCode(self::ROOT . '/tests/Service/FooTest.php', self::APP_SERVICE));
    }

    /** The checkout itself sits under a directory named tests: its production files are not test code. */
    #[Test]
    public function aProductionFileOfACheckoutUnderATestsDirectoryIsNotTestCode(): void
    {
        self::assertFalse($this->detector->isTestCode(self::ROOT . '/src/Service/Foo.php', self::APP_SERVICE));
    }

    #[Test]
    public function aTestsDirectoryDeeperInTheProjectIsNotTheProjectsTestsDirectory(): void
    {
        self::assertFalse($this->detector->isTestCode(self::ROOT . '/src/tests/Foo.php', self::APP_SERVICE));
    }

    #[Test]
    public function aTrailingSlashOnTheRootIsIgnored(): void
    {
        $detector = new TestCodeDetector('/app/');

        self::assertTrue($detector->isTestCode('/app/tests/FooTest.php', null));
        self::assertFalse($detector->isTestCode('/app/src/Foo.php', null));
    }

    #[Test]
    public function aNamespaceWithASegmentEndingInTestsIsTestCode(): void
    {
        foreach (['App\Tests', 'App\Tests\Service', 'Tests', 'Tests\Unit', 'LTS\PHPQA\Tests', 'App\FunctionalTests', 'Vendor\Pkg\IntegrationTests'] as $namespace) {
            self::assertTrue($this->detector->isTestCode(self::ROOT . '/src/Foo.php', $namespace), $namespace);
        }
    }

    /** The match is case-sensitive and at a segment's end: `Contests` ends in lower-case `tests`, `TestsHelper` only starts with it. */
    #[Test]
    public function aNamespaceWithNoSegmentEndingInTestsIsNotTestCode(): void
    {
        foreach (['App\Contests', 'App\Contests\Entry', 'App\TestsHelper', 'App\Test', 'App\Unittests'] as $namespace) {
            self::assertFalse($this->detector->isTestCode(self::ROOT . '/src/Foo.php', $namespace), $namespace);
        }
    }

    #[Test]
    public function noNamespaceDecidesOnTheFileAlone(): void
    {
        self::assertFalse($this->detector->isTestCode(self::ROOT . '/bin/tool.php', null));
        self::assertTrue($this->detector->isTestCode(self::ROOT . '/tests/bootstrap.php', null));
    }
}
