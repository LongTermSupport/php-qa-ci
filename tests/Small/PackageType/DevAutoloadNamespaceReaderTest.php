<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PackageType;

use LTS\PHPQA\PackageType\DevAutoloadNamespaceReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DevAutoloadNamespaceReader}: the seam that tells the
 * API-surface classification rule which namespaces are dev-only (autoload-dev)
 * and therefore not shipped runtime surface to classify.
 *
 * @internal
 */
#[CoversClass(DevAutoloadNamespaceReader::class)]
#[Small]
final class DevAutoloadNamespaceReaderTest extends TestCase
{
    #[Test]
    public function itReturnsTheAutoloadDevPsr4Prefixes(): void
    {
        $reader = new DevAutoloadNamespaceReader([
            'autoload-dev' => [
                'psr-4' => [
                    'Acme\Widget\Tests\\'   => 'tests/',
                    'Acme\Widget\Dev\\'     => 'src-dev/',
                    'QaConfig\\'            => 'qaConfig/',
                ],
            ],
        ]);

        self::assertSame(
            ['Acme\Widget\Tests\\', 'Acme\Widget\Dev\\', 'QaConfig\\'],
            $reader->namespacePrefixes(),
        );
    }

    #[Test]
    public function itReturnsEmptyWhenThereIsNoAutoloadDev(): void
    {
        self::assertSame([], new DevAutoloadNamespaceReader(['name' => 'acme/widget'])->namespacePrefixes());
    }

    #[Test]
    public function itReturnsEmptyWhenAutoloadDevHasNoPsr4(): void
    {
        self::assertSame([], new DevAutoloadNamespaceReader(['autoload-dev' => ['files' => ['x.php']]])->namespacePrefixes());
    }

    #[Test]
    public function itIgnoresBlankOrNonStringPrefixes(): void
    {
        $reader = new DevAutoloadNamespaceReader([
            'autoload-dev' => [
                'psr-4' => [
                    'Acme\Tests\\' => 'tests/',
                    ''             => 'blank/',
                ],
            ],
        ]);

        self::assertSame(['Acme\Tests\\'], $reader->namespacePrefixes());
    }
}
