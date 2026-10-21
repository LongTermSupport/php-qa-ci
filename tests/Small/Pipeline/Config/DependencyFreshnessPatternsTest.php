<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LogicException;
use LTS\PHPQA\Pipeline\Config\DependencyFreshnessPatterns;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DependencyFreshnessPatterns::class)]
#[UsesClass(QaConfigBuilder::class)]
#[UsesClass(EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class DependencyFreshnessPatternsTest extends TestCase
{
    private const string SELF = 'lts/php-qa-ci';

    private const string ACME_GLOB = 'acme/*';

    #[Test]
    public function theDefaultIsExactlyPhpQaCiItself(): void
    {
        $factory = ContextFactory::create();

        try {
            self::assertSame([self::SELF], $factory->builder()->build()->dependencyFreshnessPatterns);
        } finally {
            $factory->project->remove();
        }
    }

    #[Test]
    public function phpQaCiIsAlwaysFirstAndNeverDuplicated(): void
    {
        self::assertSame([self::SELF, self::ACME_GLOB], new DependencyFreshnessPatterns(self::ACME_GLOB, self::SELF, self::ACME_GLOB)->patterns);
    }

    #[Test]
    #[TestWith(['acme/one', true])]
    #[TestWith(['acme/two-things', true])]
    #[TestWith(['acmeco/one', false])]
    #[TestWith(['other/acme', false])]
    #[TestWith(['lts/php-qa-ci', true])]
    #[TestWith(['lts/other', false])]
    #[TestWith(['vendorx/wanted', true])]
    #[TestWith(['vendorx/unwanted', false])]
    public function aVendorGlobMatchesThatVendorAndAnExactNameMatchesOnePackage(string $package, bool $matches): void
    {
        self::assertSame($matches, new DependencyFreshnessPatterns(self::ACME_GLOB, 'vendorx/wanted')->matches($package));
    }

    #[Test]
    #[TestWith(['acme'])]
    #[TestWith(['*'])]
    #[TestWith(['acme/one/two'])]
    #[TestWith(['Acme/One'])]
    #[TestWith([' acme/one'])]
    #[TestWith([''])]
    public function aPatternThatCannotNameAPackageIsRefusedAtLoadTime(string $pattern): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf("dependency freshness pattern '%s'", $pattern));

        new DependencyFreshnessPatterns($pattern);
    }
}
