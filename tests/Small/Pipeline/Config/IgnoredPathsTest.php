<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(IgnoredPaths::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class IgnoredPathsTest extends TestCase
{
    private const string ROOT = '/project';

    private const string ASSETS = 'tests/assets';

    #[Test]
    public function eachPathIsResolvedAgainstTheProjectRootWhateverItsSpelling(): void
    {
        $ignored = new IgnoredPaths(self::ROOT . '/', 'tests/assets', './src/Generated/', ' bin/legacy.php ');

        self::assertSame(['/project/tests/assets', '/project/src/Generated', '/project/bin/legacy.php'], $ignored->absolute);
        self::assertFalse($ignored->isEmpty());
    }

    #[Test]
    public function aPathContainsItselfAndEverythingBelowItButNotASiblingThatSharesItsPrefix(): void
    {
        $ignored = new IgnoredPaths(self::ROOT, self::ASSETS, 'src/Legacy.php');

        self::assertTrue($ignored->contains('/project/tests/assets'));
        self::assertTrue($ignored->contains('/project/tests/assets/Fixture.php'));
        self::assertTrue($ignored->contains('/project/tests/assets/deep/Fixture.php'));
        self::assertTrue($ignored->contains('/project/src/Legacy.php'));
        self::assertFalse($ignored->contains('/project/tests/assetsExtra/Fixture.php'));
        self::assertFalse($ignored->contains('/project/tests/Unit/assets/Fixture.php'));
        self::assertFalse($ignored->contains('/project/src/Legacy.php.dist'));
    }

    #[Test]
    public function noIgnoredPathContainsNothing(): void
    {
        $ignored = new IgnoredPaths(self::ROOT);

        self::assertSame([], $ignored->absolute);
        self::assertTrue($ignored->isEmpty());
        self::assertFalse($ignored->contains('/project/src/Foo.php'));
    }

    #[Test]
    public function theConfigsIgnoredPathsAreReadAgainstItsProjectRoot(): void
    {
        $factory = ContextFactory::create();

        try {
            $config  = $factory->builder()->withIgnoredPaths(self::ASSETS)->build();
            $ignored = IgnoredPaths::of($config);

            self::assertSame([$factory->project->path . '/' . self::ASSETS], $ignored->absolute);
        } finally {
            $factory->project->remove();
        }
    }
}
