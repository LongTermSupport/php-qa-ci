<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Phar;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each committed self-built PHAR carries nothing that varies from one build to
 * the next. A rebuild with no dependency change then reproduces it byte for
 * byte, so the dependency update's diff names only the tools that really
 * changed, and the changelog it writes is complete.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class SelfBuiltPharReproducibilityTest extends TestCase
{
    /** Box names the PHAR this way when its config sets no alias: random per build. */
    private const string GENERATED_ALIAS = 'box-auto-generated-alias-';

    /** @return iterable<string, array{string}> one case per build/<tool>/ manifest */
    public static function selfBuiltTools(): iterable
    {
        foreach (new \FilesystemIterator(self::libraryRoot() . '/build') as $directory) {
            if (!$directory instanceof \SplFileInfo || !is_file($directory->getPathname() . '/composer.json')) {
                continue;
            }

            $tool = $directory->getFilename();

            yield $tool => [$tool];
        }
    }

    #[Test]
    #[DataProvider('selfBuiltTools')]
    public function itsStubNamesAFixedAlias(string $tool): void
    {
        $stub = new \Phar($this->pharPath($tool))->getStub();

        self::assertStringNotContainsString(self::GENERATED_ALIAS, $stub, \sprintf('build/%s/box.json.dist sets no "alias", so Box picks a random one per build', $tool));
    }

    #[Test]
    #[DataProvider('selfBuiltTools')]
    public function itsRootPackageRecordsNoCommitOfThisRepository(string $tool): void
    {
        $root = $this->rootPackage($tool);

        $version = $root['pretty_version'] ?? null;

        self::assertNull($root['reference'] ?? null, \sprintf('vendor-phar/%s.phar records the git commit it was built from as its root package reference: build it with COMPOSER_ROOT_VERSION set', $tool));
        self::assertIsString($version);
        self::assertStringStartsNotWith('dev-', $version, \sprintf('vendor-phar/%s.phar records the branch it was built on as its root package version', $tool));
    }

    private static function libraryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function pharPath(string $tool): string
    {
        return self::libraryRoot() . '/vendor-phar/' . $tool . '.phar';
    }

    /**
     * The root package record of Composer's generated `installed.php` inside the PHAR.
     *
     * @return array<array-key, mixed>
     */
    private function rootPackage(string $tool): array
    {
        $installed = require 'phar://' . $this->pharPath($tool) . '/vendor/composer/installed.php';
        self::assertIsArray($installed);
        self::assertIsArray($installed['root'] ?? null);

        return $installed['root'];
    }
}
