<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LogicException;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
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
final class QaConfigBuilderComposerCooldownTest extends TestCase
{
    private const string REASON = 'resolved by a vendored mirror that enforces its own delay';

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
    public function theCooldownCheckIsReportOnlyAndNotOptedOutByDefault(): void
    {
        $config = $this->factory->builder()->build();

        self::assertFalse($config->enforceComposerCooldown);
        self::assertSame([], $config->composerCooldownInternalVendors);
        self::assertNull($config->composerCooldownOptOutReason);
    }

    #[Test]
    public function theEnvironmentCanTurnEnforcementOn(): void
    {
        self::assertTrue($this->factory->builder(['enforceComposerCooldown' => '1'])->build()->enforceComposerCooldown);
    }

    #[Test]
    public function enforcementIsSwitchedOnAndOffByTheBuilder(): void
    {
        $on = $this->factory->builder()->withComposerCooldownEnforced(true);

        self::assertTrue($on->build()->enforceComposerCooldown);
        self::assertFalse($on->withComposerCooldownEnforced(false)->build()->enforceComposerCooldown);
    }

    #[Test]
    public function internalVendorsAccumulate(): void
    {
        $config = $this->factory->builder()
            ->withComposerCooldownInternalVendors('acme-tools')
            ->withComposerCooldownInternalVendors('acme.labs', 'acme_two')
            ->build()
        ;

        self::assertSame(['acme-tools', 'acme.labs', 'acme_two'], $config->composerCooldownInternalVendors);
    }

    #[Test]
    #[DataProvider('invalidVendors')]
    public function anInternalVendorThatIsNotAVendorNameIsRefused(string $vendor): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('withComposerCooldownInternalVendors');

        $this->factory->builder()->withComposerCooldownInternalVendors($vendor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVendors(): iterable
    {
        yield 'empty' => [''];

        yield 'a package name' => ['acme/lib'];

        yield 'a glob' => ['acme/*'];

        yield 'upper case' => ['Acme'];

        yield 'leading dash' => ['-acme'];

        yield 'spaces' => ['acme corp'];
    }

    #[Test]
    public function optingOutNeedsAReasonAndKeepsItTrimmed(): void
    {
        $config = $this->factory->builder()->withoutComposerCooldownCheck('  ' . self::REASON . "\n")->build();

        self::assertSame(self::REASON, $config->composerCooldownOptOutReason);
    }

    #[Test]
    public function optingOutWithAnEmptyReasonIsRefusedWithAClearError(): void
    {
        foreach (['', '   ', "\n"] as $reason) {
            try {
                $this->factory->builder()->withoutComposerCooldownCheck($reason);
                self::fail('expected LogicException');
            } catch (LogicException $logicException) {
                self::assertStringContainsString('withoutComposerCooldownCheck()', $logicException->getMessage());
                self::assertStringContainsString('non-empty reason', $logicException->getMessage());
                self::assertStringContainsString('printed on every run', $logicException->getMessage());
            }
        }
    }
}
