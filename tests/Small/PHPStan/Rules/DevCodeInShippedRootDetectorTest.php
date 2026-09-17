<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PackageType\AutoloadRootReader;
use LTS\PHPQA\PHPStan\Rules\DevCodeInShippedRootDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DevCodeInShippedRootDetector}: the pure decision behind
 * `phpqaci.devNamespaceInProductionSource`, exercised without PHPStan's Scope or
 * RuleTestCase (PHPStan ships as a PHAR here, so neither is autoloadable as a
 * Composer class) and without touching the filesystem — the decision is string
 * work over a composer layout, and every branch is reachable from one.
 *
 * @internal
 */
#[CoversClass(DevCodeInShippedRootDetector::class)]
#[UsesClass(AutoloadRootReader::class)]
#[Small]
final class DevCodeInShippedRootDetectorTest extends TestCase
{
    /** A root that need not exist: the decision is string work and reads no filesystem. */
    private const string PROJECT_ROOT = '/project';

    /** The prefix every assertion expects back, because naming the guilty root is the answer. */
    private const string SHIPPED_PREFIX = 'Acme\Widget\\';

    /** The directory that prefix maps to, so a path can be built that is genuinely under it. */
    private const string SHIPPED_DIR = 'src/';

    /** The class the two layouts disagree about: dev-only in one, shipped in the other. */
    private const string DEV_CLASS = 'Acme\Widget\Dev\ToolRunner';

    /**
     * The first-party layout AFTER the move: a declared Dev tree, so the same class
     * that is a defect under PRE_MOVE_LAYOUT is correct here.
     *
     * @var array<int|string, mixed>
     */
    private const array LIBRARY_LAYOUT = [
        'autoload'     => [
            'psr-4' => [
                self::SHIPPED_PREFIX => self::SHIPPED_DIR,
            ],
        ],
        'autoload-dev' => [
            'psr-4' => [
                'Acme\Widget\Dev\\' => 'src-dev/',
            ],
        ],
    ];

    /**
     * The layout BEFORE the move, and the one the rule exists for: nothing declares
     * a Dev tree, so a `Dev` segment anywhere under the shipped root is shipped.
     *
     * @var array<int|string, mixed>
     */
    private const array PRE_MOVE_LAYOUT = [
        'autoload'     => [
            'psr-4' => [
                self::SHIPPED_PREFIX => self::SHIPPED_DIR,
            ],
        ],
        'autoload-dev' => [
            'psr-4' => [
                'Acme\Widget\Tests\\' => 'tests/',
            ],
        ],
    ];

    #[Test]
    public function aDevSegmentNestedUnderTheShippedRootNamesThatRoot(): void
    {
        self::assertSame(
            self::SHIPPED_PREFIX,
            $this->detector()->shippedRootCarryingDevCode(
                'Acme\Widget\Command\Dev\SandboxPushCommand',
                '/project/src/Command/Dev/SandboxPushCommand.php',
            ),
        );
    }

    #[Test]
    public function aDevSegmentDirectlyBeneathTheShippedRootNamesThatRoot(): void
    {
        self::assertSame(
            self::SHIPPED_PREFIX,
            $this->detector(self::PRE_MOVE_LAYOUT)->shippedRootCarryingDevCode(
                self::DEV_CLASS,
                '/project/src/Dev/ToolRunner.php',
            ),
        );
    }

    #[Test]
    public function aDevDirectoryIsReportedEvenWhenTheNamespaceDoesNotSayDev(): void
    {
        // A file whose layout has drifted from its namespace is still installed by
        // the root that contains it, so the path is read as well as the namespace.
        self::assertSame(
            self::SHIPPED_PREFIX,
            $this->detector()->shippedRootCarryingDevCode(
                'Acme\Widget\Tooling\Misplaced',
                '/project/src/Dev/Misplaced.php',
            ),
        );
    }

    #[Test]
    public function aDevNamespaceIsReportedEvenWhenTheFileIsUnknown(): void
    {
        // Reflection resolves no file for internal or eval'd code; the namespace
        // alone is enough to place the class under the shipped root.
        self::assertSame(
            self::SHIPPED_PREFIX,
            $this->detector(self::PRE_MOVE_LAYOUT)->shippedRootCarryingDevCode(self::DEV_CLASS, null),
        );
    }

    #[Test]
    public function aRootMappedToTheProjectDirectoryItselfIsStillAShippedRoot(): void
    {
        $detector = $this->detector([
            'autoload' => ['psr-4' => ['Acme\\' => './']],
        ]);

        self::assertSame(
            'Acme\\',
            $detector->shippedRootCarryingDevCode('Acme\Dev\ToolRunner', '/project/Dev/ToolRunner.php'),
        );
    }

    #[Test]
    public function aPrefixMappedToSeveralDirectoriesIsMatchedOnAnyOfThem(): void
    {
        $detector = $this->detector([
            'autoload' => ['psr-4' => ['Acme\\' => ['src/', 'lib/']]],
        ]);

        self::assertSame(
            'Acme\\',
            $detector->shippedRootCarryingDevCode('Other\Tooling\Thing', '/project/lib/Dev/Thing.php'),
        );
    }

    #[Test]
    public function aDevNamespaceDeclaredUnderAutoloadDevIsNotReported(): void
    {
        self::assertNull(
            $this->detector()->shippedRootCarryingDevCode(
                self::DEV_CLASS,
                '/project/src-dev/ToolRunner.php',
            ),
        );
    }

    #[Test]
    public function aFileUnderADevDirectoryIsNotReportedEvenWhenItsNamespaceIsShipped(): void
    {
        // The directory is what `composer install --no-dev` drops, so a file
        // inside one is not shipped whatever its namespace claims. This class's
        // namespace is under the SHIPPED prefix and matches no dev prefix; only
        // its location places it.
        self::assertNull(
            $this->detector()->shippedRootCarryingDevCode(
                'Acme\Widget\Tooling\Thing',
                '/project/src-dev/Dev/Thing.php',
            ),
        );
    }

    #[Test]
    public function aSegmentThatMerelyStartsWithDevIsNotADevSegment(): void
    {
        self::assertNull(
            $this->detector()->shippedRootCarryingDevCode(
                'Acme\Widget\DevTools\Helper',
                '/project/src/DevTools/Helper.php',
            ),
        );
    }

    #[Test]
    public function aClassWhoseOwnNameIsDevIsNotADevTree(): void
    {
        // The class's short name is not part of the tree it sits in, and neither
        // is the file's basename.
        self::assertNull(
            $this->detector()->shippedRootCarryingDevCode('Acme\Widget\Tooling\Dev', '/project/src/Tooling/Dev.php'),
        );
    }

    #[Test]
    public function aClassUnderNoShippedRootIsNotReported(): void
    {
        self::assertNull(
            $this->detector()->shippedRootCarryingDevCode('Other\Vendor\Dev\Thing', '/elsewhere/src/Dev/Thing.php'),
        );
    }

    #[Test]
    public function aProjectDeclaringNoShippedRootsReportsNothing(): void
    {
        $detector = $this->detector(['name' => 'acme/widget']);

        self::assertNull(
            $detector->shippedRootCarryingDevCode(self::DEV_CLASS, '/project/src/Dev/ToolRunner.php'),
        );
    }

    /**
     * @param array<int|string, mixed>|null $composerJson defaults to the first-party layout
     */
    private function detector(?array $composerJson = null): DevCodeInShippedRootDetector
    {
        return new DevCodeInShippedRootDetector(
            new AutoloadRootReader($composerJson ?? self::LIBRARY_LAYOUT),
            self::PROJECT_ROOT,
        );
    }
}
