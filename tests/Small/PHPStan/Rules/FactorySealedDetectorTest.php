<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\FactorySealedDetector;
use LTS\PHPQA\PHPStan\Rules\TestCodeDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pure decision logic of FactorySealedRule via its extracted
 * FactorySealedDetector helper.
 *
 * PHPStan ships as a PHAR here (not a Composer library), so RuleTestCase and
 * Scope are unavailable. The decision is extracted into a framework-free
 * detector so all four cases can be asserted directly.
 *
 * @internal
 */
#[CoversClass(FactorySealedDetector::class)]
#[UsesClass(TestCodeDetector::class)]
#[Small]
final class FactorySealedDetectorTest extends TestCase
{
    private const string FACTORY = 'Acme\Widget\WidgetFactory';

    private const string SERVICE = 'Acme\Widget\SomeService';

    private const string PACKAGE_TEST_FILE = '/project/packages/foo/tests/WidgetTest.php';

    private FactorySealedDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new FactorySealedDetector(new TestCodeDetector('/project'));
    }

    /** A checkout under a directory named tests: its production code is not exempted. */
    public function testConstructionInProductionCodeOfACheckoutUnderATestsDirectoryIsAViolation(): void
    {
        $detector = new FactorySealedDetector(new TestCodeDetector('/home/ci/tests/project'));

        self::assertTrue(
            $detector->isViolation(
                sealedFactory: self::FACTORY,
                enclosingClass: self::SERVICE,
                filePath: '/home/ci/tests/project/src/SomeService.php',
            ),
            "Only the project's own tests/ exempts a construction, not a tests directory above the project",
        );
    }

    /** A monorepo package's test, outside the project's own tests/, is exempt by its namespace. */
    public function testConstructionInAPackageTestOutsideTheProjectsTestsIsNotAViolation(): void
    {
        self::assertFalse(
            $this->detector->isViolation(
                sealedFactory: self::FACTORY,
                enclosingClass: 'Vendor\Pkg\Tests\WidgetTest',
                filePath: self::PACKAGE_TEST_FILE,
            ),
            'A class in a namespace with a segment ending in Tests is test code wherever its file is',
        );
    }

    /** Outside the project's own tests/, a class in a non-test namespace is production code. */
    public function testConstructionInANonTestNamespaceOutsideTheProjectsTestsIsAViolation(): void
    {
        self::assertTrue(
            $this->detector->isViolation(
                sealedFactory: self::FACTORY,
                enclosingClass: 'Vendor\Pkg\WidgetHelper',
                filePath: self::PACKAGE_TEST_FILE,
            ),
            "A package tests/ directory is not the project's tests/, so only the namespace can exempt it",
        );
    }

    public function testConstructionInANonFactoryClassIsAViolation(): void
    {
        self::assertTrue(
            $this->detector->isViolation(
                sealedFactory: self::FACTORY,
                enclosingClass: self::SERVICE,
                filePath: '/project/src/SomeService.php',
            ),
            'A new <sealed>() in a non-factory production class must be a violation',
        );
    }

    public function testConstructionInsideTheAuthorisedFactoryIsNotAViolation(): void
    {
        self::assertFalse(
            $this->detector->isViolation(
                sealedFactory: self::FACTORY,
                enclosingClass: self::FACTORY,
                filePath: '/project/src/WidgetFactory.php',
            ),
            'The authorised factory may construct the sealed type',
        );
    }

    public function testConstructionInATestFileIsNotAViolation(): void
    {
        self::assertFalse(
            $this->detector->isViolation(
                sealedFactory: self::FACTORY,
                enclosingClass: 'Acme\Widget\Tests\WidgetTest',
                filePath: '/project/tests/WidgetTest.php',
            ),
            'Tests construct sealed types freely',
        );
    }

    public function testConstructionOfAnUnsealedTypeIsNotAViolation(): void
    {
        self::assertFalse(
            $this->detector->isViolation(
                sealedFactory: null,
                enclosingClass: self::SERVICE,
                filePath: '/project/src/SomeService.php',
            ),
            'A type with no sealing attribute is never a violation',
        );
    }

    public function testFileScopeConstructionOfASealedTypeIsAViolation(): void
    {
        self::assertTrue(
            $this->detector->isViolation(
                sealedFactory: self::FACTORY,
                enclosingClass: null,
                filePath: '/project/src/bootstrap.php',
            ),
            'A new <sealed>() at file scope (no enclosing class) is a violation',
        );
    }
}
