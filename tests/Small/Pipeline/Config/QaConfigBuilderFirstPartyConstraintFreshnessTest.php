<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The switch for the first-party constraint freshness check of the
 * dependencyFreshness lane: on by default, off by the builder or the
 * environment.
 *
 * @internal
 */
#[CoversClass(QaConfigBuilder::class)]
#[UsesClass(QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class QaConfigBuilderFirstPartyConstraintFreshnessTest extends TestCase
{
    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function theCheckIsOnByDefault(): void
    {
        self::assertTrue($this->factory->builder()->build()->useFirstPartyConstraintFreshness);
    }

    #[Test]
    public function theEnvironmentTurnsItOffForOneRun(): void
    {
        self::assertFalse($this->factory->builder(['useFirstPartyConstraintFreshness' => '0'])->build()->useFirstPartyConstraintFreshness);
    }

    #[Test]
    public function theBuilderTurnsItOffAndOnAndKeepsTheOtherSettings(): void
    {
        $off = $this->factory->builder()->withComposerAudit(false)->withFirstPartyConstraintFreshness(false);

        self::assertFalse($off->build()->useFirstPartyConstraintFreshness);
        self::assertFalse($off->build()->useComposerAudit);
        self::assertTrue($off->withFirstPartyConstraintFreshness(true)->build()->useFirstPartyConstraintFreshness);
    }
}
