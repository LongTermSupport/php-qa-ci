<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PackageType;

use LTS\PHPQA\PackageType\AutoloadRootReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AutoloadRootReader}: the seam that tells a rule which PSR-4
 * roots of the analysed project are SHIPPED (`autoload`) and which are dev-only
 * (`autoload-dev`).
 *
 * @internal
 */
#[CoversClass(AutoloadRootReader::class)]
#[Small]
final class AutoloadRootReaderTest extends TestCase
{
    /** @var array<int|string, mixed> the layout every first-party library here uses */
    private const array LIBRARY_LAYOUT = [
        'autoload'     => [
            'psr-4' => [
                'Acme\Widget\\' => 'src/',
            ],
        ],
        'autoload-dev' => [
            'psr-4' => [
                'Acme\Widget\Dev\\'   => 'src-dev/',
                'Acme\Widget\Tests\\' => 'tests/',
                'QaConfig\\'          => ['qaConfig/'],
            ],
        ],
    ];

    #[Test]
    public function itReadsTheShippedPsr4Roots(): void
    {
        self::assertSame(
            ['Acme\Widget\\' => ['src/']],
            new AutoloadRootReader(self::LIBRARY_LAYOUT)->productionRoots(),
        );
    }

    #[Test]
    public function itReadsTheDevOnlyPsr4Roots(): void
    {
        self::assertSame(
            [
                'Acme\Widget\Dev\\'   => ['src-dev/'],
                'Acme\Widget\Tests\\' => ['tests/'],
                'QaConfig\\'          => ['qaConfig/'],
            ],
            new AutoloadRootReader(self::LIBRARY_LAYOUT)->devRoots(),
        );
    }

    #[Test]
    public function aPrefixMappedToSeveralDirectoriesKeepsAllOfThem(): void
    {
        $reader = new AutoloadRootReader([
            'autoload' => [
                'psr-4' => [
                    'Acme\Widget\\' => ['src/', 'lib/'],
                ],
            ],
        ]);

        self::assertSame(['Acme\Widget\\' => ['src/', 'lib/']], $reader->productionRoots());
    }

    #[Test]
    public function anAbsentSectionReadsAsNoRoots(): void
    {
        $reader = new AutoloadRootReader(['name' => 'acme/widget']);

        self::assertSame([], $reader->productionRoots());
        self::assertSame([], $reader->devRoots());
    }

    #[Test]
    public function aSectionWithoutPsr4ReadsAsNoRoots(): void
    {
        $reader = new AutoloadRootReader([
            'autoload'     => ['classmap' => ['src/']],
            'autoload-dev' => ['files' => ['tests/bootstrap.php']],
        ]);

        self::assertSame([], $reader->productionRoots());
        self::assertSame([], $reader->devRoots());
    }

    #[Test]
    public function aBlankPrefixOrANonStringDirectoryIsDiscarded(): void
    {
        $reader = new AutoloadRootReader([
            'autoload' => [
                'psr-4' => [
                    'Acme\Widget\\' => 'src/',
                    ''              => 'blank/',
                    'Acme\Broken\\' => ['lib/', 42],
                ],
            ],
        ]);

        self::assertSame(
            [
                'Acme\Widget\\' => ['src/'],
                'Acme\Broken\\' => ['lib/'],
            ],
            $reader->productionRoots(),
        );
    }
}
