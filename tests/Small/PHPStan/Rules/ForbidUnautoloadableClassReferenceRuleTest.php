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

    /** @var array<int|string, mixed> */
    private array $composerJson = [];

    /**
     * The fixtures are loaded first so the reflection provider knows their classes, as a
     * full analysis knows every class in its analysed paths.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->allFixtures() as $fixture) {
            require_once $fixture;
        }

        $this->composerJson = [
            'autoload-dev' => ['psr-4' => [self::FIXTURE_NAMESPACE => self::FIXTURE_DIRECTORY]],
        ];
    }

    #[Test]
    public function everyReferenceFromAnotherFileToAClassAtTheFootOfAFileIsReported(): void
    {
        $stranded  = $this->message('StrandedHelper', self::HOST, 'StrandedHelper.php');
        $exception = $this->message('StrandedException', self::HOST, 'StrandedException.php');
        $misplaced = $this->message('Elsewhere\Misplaced', self::FIXTURE_DIRECTORY . 'Drifted/Misplaced.php', 'Elsewhere/Misplaced.php');

        $this->analyse($this->allFixtures(), [
            [$stranded, 12],
            [$stranded, 14],
            [$stranded, 16],
            [$stranded, 19],
            [$stranded, 21],
            [$stranded, 22],
            [$stranded, 23],
            [$exception, 28],
            [$stranded, 34],
            [$misplaced, 45],
            [$misplaced, 47],
        ]);
    }

    #[Test]
    public function aClassmapEntryCoveringTheDeclaringFileLoadsIt(): void
    {
        $this->composerJson = [
            'autoload-dev' => [
                'psr-4'    => [self::FIXTURE_NAMESPACE => self::FIXTURE_DIRECTORY],
                'classmap' => [self::HOST, self::FIXTURE_DIRECTORY . 'Drifted/'],
            ],
        ];

        $this->analyse($this->allFixtures(), []);
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
        return array_map(
            fn (string $relative): string => $this->repositoryRoot() . '/' . self::FIXTURE_DIRECTORY . $relative,
            ['AtItsPath.php', 'Consumer.php', 'Drifted/Misplaced.php', 'HostTest.php', 'Includer.php', 'unclaimed-stubs.php'],
        );
    }

    private function message(string $class, string $declaredIn, string $expected): string
    {
        return \sprintf(
            'Class %s cannot be autoloaded: it is declared in %s, not at %s, so this reference finds it only if that '
            . 'file was loaded first. Move the class to its own file at that path.',
            self::FIXTURE_NAMESPACE . $class,
            $declaredIn,
            self::FIXTURE_DIRECTORY . $expected,
        );
    }

    private function repositoryRoot(): string
    {
        return \Safe\realpath(__DIR__ . '/../../../..');
    }
}
