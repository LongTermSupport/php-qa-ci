<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\Helper;
use LTS\PHPQA\PHPStan\Rules\AutoloadPathDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AutoloadPathDetector}: the decision behind
 * `phpqaci.unautoloadableClassReference`, made from a decoded composer.json and a path.
 *
 * The paths need not exist: a path that does not resolve is compared as written,
 * after its `./` and doubled separators are folded away.
 *
 * @internal
 */
#[CoversClass(AutoloadPathDetector::class)]
#[UsesClass(Helper::class)]
#[Small]
final class AutoloadPathDetectorTest extends TestCase
{
    private const string ROOT = '/project';

    private const string TESTS_DIR = 'tests/';

    private const string TESTS_PREFIX = 'Acme\Widget\Tests\\';

    private const string HELPER = 'Acme\Widget\Tests\Runner\AlwaysRetry';

    private const string HELPER_PATH = self::ROOT . '/tests/Runner/AlwaysRetry.php';

    private const string HOST_FILE = self::ROOT . '/tests/Runner/ToolExecutorTest.php';

    private const string FIXTURE_NAMESPACE = 'LTS\PHPQA\Tests\Assets\PHPStan\UnautoloadableClassReference\\';

    private const string FIXTURE_DIRECTORY = 'tests/assets/PHPStan/UnautoloadableClassReference/';

    /** @var array<int|string, mixed> */
    private const array TESTS_LAYOUT = [
        'autoload'     => ['psr-4' => ['Acme\Widget\\' => 'src/']],
        'autoload-dev' => ['psr-4' => [self::TESTS_PREFIX => self::TESTS_DIR]],
    ];

    #[Test]
    public function aClassAtItsPsr4PathIsLoadable(): void
    {
        self::assertNull($this->detector(self::TESTS_LAYOUT)->expectedPathWhenUnloadable(self::HELPER, self::HELPER_PATH));
    }

    #[Test]
    public function aClassDeclaredInAnotherFileIsGivenThePathItsAutoloaderLooksAt(): void
    {
        self::assertSame(
            self::HELPER_PATH,
            $this->detector(self::TESTS_LAYOUT)->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE),
        );
    }

    #[Test]
    public function aFileWhoseNamespaceDriftedFromItsDirectoryIsNotLoadable(): void
    {
        self::assertSame(
            self::HELPER_PATH,
            $this->detector(self::TESTS_LAYOUT)->expectedPathWhenUnloadable(self::HELPER, self::ROOT . '/tests/Other/AlwaysRetry.php'),
        );
    }

    #[Test]
    public function aClassNoPrefixClaimsIsNotJudged(): void
    {
        self::assertNull($this->detector(self::TESTS_LAYOUT)->expectedPathWhenUnloadable(\Composer\Composer::class, self::ROOT . '/tests/stubs.php'));
    }

    #[Test]
    public function aPrefixIsMatchedAtANamespaceBoundaryAsComposerWritesIt(): void
    {
        // `Acme\WidgetExtra\Foo` does not start with `Acme\Widget\`, so nothing claims it.
        self::assertNull($this->detector(self::TESTS_LAYOUT)->expectedPathWhenUnloadable('Acme\WidgetExtra\Foo', self::HOST_FILE));
    }

    #[Test]
    public function theMostSpecificPrefixNamesTheExpectedPath(): void
    {
        $layout = ['autoload-dev' => ['psr-4' => [
            self::TESTS_PREFIX                    => self::TESTS_DIR,
            'Acme\Widget\Tests\Assets\Fixtures\\' => 'tests/assets/fixtures/',
        ]]];

        self::assertSame(
            self::ROOT . '/tests/assets/fixtures/Helper.php',
            $this->detector($layout)->expectedPathWhenUnloadable('Acme\Widget\Tests\Assets\Fixtures\Helper', self::HOST_FILE),
        );
    }

    #[Test]
    public function aClassAtThePathOfAnyPrefixClaimingItIsLoadable(): void
    {
        $layout = ['autoload-dev' => ['psr-4' => [
            self::TESTS_PREFIX           => self::TESTS_DIR,
            'Acme\Widget\Tests\Assets\\' => 'tests/assets/',
        ]]];

        self::assertNull($this->detector($layout)->expectedPathWhenUnloadable('Acme\Widget\Tests\Assets\Helper', self::ROOT . '/tests/Assets/Helper.php'));
    }

    #[Test]
    public function aPrefixMappingSeveralDirectoriesLoadsFromAnyOfThem(): void
    {
        $layout = ['autoload-dev' => ['psr-4' => [self::TESTS_PREFIX => [self::TESTS_DIR, './tests-legacy//']]]];

        self::assertNull($this->detector($layout)->expectedPathWhenUnloadable(self::HELPER, self::ROOT . '/tests-legacy/Runner/AlwaysRetry.php'));
    }

    #[Test]
    public function aPsr0ClassIsFoundByItsNamespaceAndItsUnderscores(): void
    {
        $layout   = ['autoload' => ['psr-0' => ['Legacy_' => 'lib/', 'Acme\Old\\' => 'old']]];
        $detector = $this->detector($layout);

        self::assertNull($detector->expectedPathWhenUnloadable('Legacy_Widget_Store', self::ROOT . '/lib/Legacy/Widget/Store.php'));
        self::assertNull($detector->expectedPathWhenUnloadable('Acme\Old\Sub\Some_Thing', self::ROOT . '/old/Acme/Old/Sub/Some/Thing.php'));
        self::assertSame(
            self::ROOT . '/lib/Legacy/Widget/Store.php',
            $detector->expectedPathWhenUnloadable('Legacy_Widget_Store', self::ROOT . '/lib/Legacy/helpers.php'),
        );
    }

    #[Test]
    public function aFileUnderAClassmapDirectoryIsLoadedWhateverItDeclares(): void
    {
        $layout                             = self::TESTS_LAYOUT;
        $layout['autoload-dev']['classmap'] = ['tests/Runner/'];

        self::assertNull($this->detector($layout)->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE));
    }

    #[Test]
    public function aClassmapDirectoryDoesNotCoverASiblingSharingItsPrefix(): void
    {
        $layout                             = self::TESTS_LAYOUT;
        $layout['autoload-dev']['classmap'] = ['tests/Run'];

        self::assertSame(self::HELPER_PATH, $this->detector($layout)->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE));
    }

    #[Test]
    public function aFileNamedInClassmapOrFilesIsLoaded(): void
    {
        $classmap                             = self::TESTS_LAYOUT;
        $classmap['autoload-dev']['classmap'] = ['tests/Runner/ToolExecutorTest.php'];
        $files                                = self::TESTS_LAYOUT;
        $files['autoload-dev']['files']       = ['./tests/Runner/ToolExecutorTest.php'];

        self::assertNull($this->detector($classmap)->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE));
        self::assertNull($this->detector($files)->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE));
    }

    #[Test]
    public function aMalformedOrAbsentAutoloadSectionClaimsNothing(): void
    {
        $malformed = [
            'autoload'     => 'src/',
            'autoload-dev' => ['psr-4' => self::TESTS_DIR, 'psr-0' => [7 => 'x'], 'classmap' => self::TESTS_DIR, 'files' => [3]],
        ];

        self::assertNull($this->detector([])->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE));
        self::assertNull($this->detector($malformed)->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE));
    }

    #[Test]
    public function aRealFileIsComparedByItsResolvedPath(): void
    {
        // This test file is real: reached through a `..` it still resolves to itself.
        $root   = \Safe\realpath(__DIR__ . '/../../../..');
        $layout = ['autoload-dev' => ['psr-4' => ['LTS\PHPQA\Tests\\' => self::TESTS_DIR]]];

        self::assertNull(
            new AutoloadPathDetector($root, $layout)->expectedPathWhenUnloadable(
                self::class,
                __DIR__ . '/../Rules/AutoloadPathDetectorTest.php',
            ),
        );
    }

    #[Test]
    public function aFileInsideAStreamIsComparedAsWritten(): void
    {
        // A phar's member exists to file_exists() but has no real path.
        $phar = 'phar://' . \Safe\realpath(__DIR__ . '/../../../../vendor-phar/phpstan.phar') . '/conf/config.neon';

        self::assertSame(self::HELPER_PATH, $this->detector(self::TESTS_LAYOUT)->expectedPathWhenUnloadable(self::HELPER, $phar));
    }

    #[Test]
    public function withNoOverrideItReadsTheProjectsComposerJson(): void
    {
        $root = \Safe\realpath(__DIR__ . '/../../../..');

        self::assertSame(
            $root . '/tests/Small/PHPStan/Rules/Elsewhere.php',
            new AutoloadPathDetector($root)->expectedPathWhenUnloadable(
                'LTS\PHPQA\Tests\Small\PHPStan\Rules\Elsewhere',
                __FILE__,
            ),
        );
    }

    #[Test]
    public function aProjectWithNoComposerJsonClaimsNothing(): void
    {
        self::assertNull(new AutoloadPathDetector(__DIR__)->expectedPathWhenUnloadable(self::HELPER, self::HOST_FILE));
    }

    #[Test]
    public function aPathIsNamedRelativeToTheProjectRootWhenItIsUnderIt(): void
    {
        $detector = $this->detector([]);

        self::assertSame('tests/Runner/AlwaysRetry.php', $detector->relativeToRoot(self::HELPER_PATH));
        self::assertSame('/projectile/Foo.php', $detector->relativeToRoot('/projectile/./Foo.php'));
    }

    #[Test]
    public function aSecondDeclarationIsGivenTheClaimedPathThatAlsoDeclaresTheClass(): void
    {
        $fixtures = $this->fixtureDetector();

        self::assertSame(
            $this->fixturePath('Duplicated.php'),
            $fixtures->claimedPathDeclaring(self::FIXTURE_NAMESPACE . 'Duplicated', $this->fixturePath('Drifted/Copies.php')),
        );
    }

    #[Test]
    public function noClaimedPathDeclaresAClassThatHasOnlyOneDeclaration(): void
    {
        $fixtures = $this->fixtureDetector();

        // The claimed path is the declaring file itself.
        self::assertNull($fixtures->claimedPathDeclaring(self::FIXTURE_NAMESPACE . 'Duplicated', $this->fixturePath('Duplicated.php')));
        // No file exists at the claimed path.
        self::assertNull($fixtures->claimedPathDeclaring(self::FIXTURE_NAMESPACE . 'StrandedHelper', $this->fixturePath('HostTest.php')));
        // A file exists at the claimed path, and declares another class.
        self::assertNull($fixtures->claimedPathDeclaring(self::FIXTURE_NAMESPACE . 'Drifted\Misplaced', $this->fixturePath('HostTest.php')));
        // Nothing claims the class.
        self::assertNull($this->detector(self::TESTS_LAYOUT)->claimedPathDeclaring(self::HELPER, self::HOST_FILE));
    }

    private function fixtureDetector(): AutoloadPathDetector
    {
        return new AutoloadPathDetector(
            \Safe\realpath(__DIR__ . '/../../../..'),
            ['autoload-dev' => ['psr-4' => [self::FIXTURE_NAMESPACE => self::FIXTURE_DIRECTORY]]],
        );
    }

    private function fixturePath(string $relative): string
    {
        return \Safe\realpath(__DIR__ . '/../../../../' . self::FIXTURE_DIRECTORY . $relative);
    }

    /**
     * @param array<int|string, mixed> $composerJson
     */
    private function detector(array $composerJson): AutoloadPathDetector
    {
        return new AutoloadPathDetector(self::ROOT, $composerJson);
    }
}
