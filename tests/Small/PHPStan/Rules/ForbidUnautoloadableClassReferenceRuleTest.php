<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\AutoloadPathDetector;
use LTS\PHPQA\PHPStan\Rules\ClassReferenceScanner;
use LTS\PHPQA\PHPStan\Rules\ForbidUnautoloadableClassReferenceRule;
use LTS\PHPQA\PHPStan\Rules\VendoredCodeDetector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidUnautoloadableClassReferenceRule>
 */
#[CoversClass(ForbidUnautoloadableClassReferenceRule::class)]
#[CoversClass(ClassReferenceScanner::class)]
#[UsesClass(AutoloadPathDetector::class)]
#[UsesClass(VendoredCodeDetector::class)]
#[Medium]
final class ForbidUnautoloadableClassReferenceRuleTest extends RuleTestCase
{
    private const string FIXTURE_NAMESPACE = 'LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference\\';

    private const string FIXTURE_DIRECTORY = 'tests/assets/PHPStan/UnautoloadableClassReference/';

    private const string HOST = self::FIXTURE_DIRECTORY . 'HostTest.php';

    private const string STRANDED_HELPER = 'StrandedHelper';

    private const string LOADING ='this reference fails with a "not found" error unless that file was loaded first.';

    private const string CLASS_NAME = 'whatever loads the class by this name (a mock, a container, class_exists()) '
        . 'fails, or answers false, unless that file was loaded first.';

    private const string ATTRIBUTE = 'reading this attribute with newInstance() fails with "Attribute class not found" '
        . 'unless that file was loaded first.';

    private const array LOADED_FIXTURES = [
        'HostTest.php',
        'AtItsPath.php',
        'Duplicated.php',
        'Drifted/Misplaced.php',
        'unclaimed-stubs.php',
        'Consumer.php',
        'Includer.php',
    ];

    /** @var array<int|string, mixed> */
    private array $composerJson = [];

    /**
     * The fixtures are loaded first so the reflection provider knows their classes, as a
     * full analysis knows every class in its analysed paths: declaring files first.
     * Drifted/Copies.php is analysed but never loaded, because it declares a class
     * Duplicated.php declares too.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::LOADED_FIXTURES as $fixture) {
            require_once $this->fixture($fixture);
        }

        $this->composerJson = [
            'autoload-dev' => ['psr-4' => [self::FIXTURE_NAMESPACE => self::FIXTURE_DIRECTORY]],
        ];
    }

    /**
     * Only a position at which PHP asks the autoloader for the class is reported, each
     * with what fails there: `instanceof`, `catch`, a type outside an inherited signature
     * and a `::class` compared as text are quiet, because no value of an unloaded class
     * can exist for them to judge wrongly.
     */
    #[Test]
    public function everyReferenceThatMakesPhpLoadAStrandedClassIsReportedWithWhatFailsThere(): void
    {
        $stranded  = $this->atTheFootOfHost(self::STRANDED_HELPER, self::LOADING);
        $misplaced = $this->misplaced('Elsewhere\Misplaced', self::FIXTURE_DIRECTORY . 'Drifted/Misplaced.php', 'Elsewhere/Misplaced.php', self::LOADING);

        $this->analyse($this->allFixtures(), [
            [$this->atTheFootOfHost('StrandedAttribute', self::ATTRIBUTE), 10],
            [$this->atTheFootOfHost('StrandedBase', self::LOADING), 11],
            [$this->atTheFootOfHost('StrandedContract', self::LOADING), 11],
            [$this->atTheFootOfHost('StrandedTrait', self::LOADING), 13],
            [$stranded, 19],
            [$stranded, 24],
            [$stranded, 26],
            [$this->atTheFootOfHost(self::STRANDED_HELPER, self::CLASS_NAME), 40],
            [$this->atTheFootOfHost(self::STRANDED_HELPER, $this->signature('contracted')), 43],
            [$stranded, 45],
            [$this->atTheFootOfHost('StrandedMarker', $this->signature('accept')), 48],
            [$stranded, 58],
            [$this->miscased(), 72],
            [$misplaced, 77],
            [$this->duplicated(), 11],
        ]);
    }

    /**
     * A classmap loads a misplaced class wherever it is, but not a name written in the
     * wrong case, and a second declaration is still a second declaration.
     */
    #[Test]
    public function aClassmapEntryCoveringTheDeclaringFileLoadsIt(): void
    {
        $this->composerJson = [
            'autoload-dev' => [
                'psr-4'    => [self::FIXTURE_NAMESPACE => self::FIXTURE_DIRECTORY],
                'classmap' => [self::HOST, self::FIXTURE_DIRECTORY . 'Drifted/'],
            ],
        ];

        $this->analyse($this->allFixtures(), [
            [$this->miscased(), 72],
            [$this->duplicated(), 11],
        ]);
    }

    #[Test]
    public function aProjectWhoseMappingClaimsNothingHasNothingToReport(): void
    {
        $this->composerJson = ['name' => 'acme/widget'];

        $this->analyse($this->allFixtures(), []);
    }

    protected function getRule(): Rule
    {
        $root = $this->repositoryRoot();

        return new ForbidUnautoloadableClassReferenceRule(
            self::createReflectionProvider(),
            new AutoloadPathDetector($root, $this->composerJson),
            new VendoredCodeDetector($root),
            new ClassReferenceScanner(),
        );
    }

    /**
     * @return list<string>
     */
    private function allFixtures(): array
    {
        return array_map($this->fixture(...), [...self::LOADED_FIXTURES, 'Drifted/Copies.php']);
    }

    private function fixture(string $relative): string
    {
        return $this->repositoryRoot() . '/' . self::FIXTURE_DIRECTORY . $relative;
    }

    private function misplaced(string $class, string $declaredIn, string $expected, string $consequence): string
    {
        return \sprintf(
            'Class %s cannot be autoloaded: it is declared in %s, not at %s, so %s Move the class to its own file at that path.',
            self::FIXTURE_NAMESPACE . $class,
            $declaredIn,
            self::FIXTURE_DIRECTORY . $expected,
            $consequence,
        );
    }

    private function atTheFootOfHost(string $class, string $consequence): string
    {
        return $this->misplaced($class, self::HOST, $class . '.php', $consequence);
    }

    private function signature(string $method): string
    {
        return \sprintf(
            'declaring this class makes PHP load it to check this signature against %s::%s(), which fails with '
            . '"Could not check compatibility" unless that file was loaded first.',
            self::FIXTURE_NAMESPACE . 'StrandedContract',
            $method,
        );
    }

    private function miscased(): string
    {
        return \sprintf(
            'Class %s is written here as %s: the autoloader looks for the name as written, at %s, where a '
            . 'case-sensitive filesystem has no file, so %s Write the name as it is declared.',
            self::FIXTURE_NAMESPACE . 'AtItsPath',
            self::FIXTURE_NAMESPACE . 'atitspath',
            self::FIXTURE_DIRECTORY . 'atitspath.php',
            self::LOADING,
        );
    }

    private function duplicated(): string
    {
        return \sprintf(
            'Class %s is also declared at %s, where the autoloader finds it: loading this file after that one fails '
            . 'with "Cannot declare class", and loading it first gives every other file this copy. Delete this copy.',
            self::FIXTURE_NAMESPACE . 'Duplicated',
            self::FIXTURE_DIRECTORY . 'Duplicated.php',
        );
    }

    private function repositoryRoot(): string
    {
        return \Safe\realpath(__DIR__ . '/../../../..');
    }
}
