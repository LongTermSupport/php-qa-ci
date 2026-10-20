<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Lane\Phpstan\DiagnoseTurboProbe;
use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanSetupCache;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\ContextFactory;
use LTS\PHPQA\Turbo\TurboPlatform;
use LTS\PHPQA\Turbo\TurboStateEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The PHPStan lane's question to the shipped phar: is Turbo running? Asked through `diagnose`,
 * invoked as the lane invokes `analyse` (Xdebug off, the memory limit, the lane's wrapper neon),
 * and judged against the host: a host the committed manifest ships a build for is one where
 * Turbo should be running.
 *
 * @internal
 */
#[CoversClass(DiagnoseTurboProbe::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboStatus::class)]
#[UsesClass(TurboStateEnum::class)]
#[UsesClass(TurboPlatform::class)]
#[UsesClass(\LTS\PHPQA\Turbo\TurboManifest::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(PhpstanSetupCache::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanSetupKey::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\InstalledPhpstanExtensions::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto::class)]
#[Small]
final class DiagnoseTurboProbeTest extends TestCase
{
    private const string WRAPPER = '/p/var/qa/phpstan_logs/phpstan-parallel.neon';

    private const string NOT_LOADED = "Turbo extension: not loaded\nTurbo worker binary: none found\n";

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
    public function itAsksTheShippedPharThroughDiagnoseAsTheLaneRunsIt(): void
    {
        $this->factory->processes->willSucceed("Turbo extension: enabled (version 6351afb)\n");
        $config = $this->factory->builder(ci: true)->build();

        $status = $this->probe($this->shippedHost())->status($this->factory->context($config), self::WRAPPER);

        self::assertSame(TurboStateEnum::Enabled, $status->state);
        $spec = $this->factory->processes->lastSpec();
        self::assertSame($config->paths->pharDir . '/phpstan.phar', $spec->command[$this->indexOf('-f', ...$spec->command) + 1]);
        self::assertSame(['diagnose', '--no-interaction', '-c', self::WRAPPER], \array_slice($spec->command, $this->indexOf('--', ...$spec->command) + 1));
        self::assertSame($config->paths->projectRoot, $spec->cwd);
        self::assertFalse($spec->streamOutput, 'the diagnose report is read, not shown');
    }

    /** The committed manifest pins a build for 8.5 on glibc x86_64, so Turbo should be running there. */
    #[Test]
    public function aHostTheManifestShipsABuildForReportsAMissingTurbo(): void
    {
        $this->factory->processes->willSucceed(self::NOT_LOADED);

        $status = $this->probe($this->shippedHost())->status($this->factory->context(), self::WRAPPER);

        self::assertSame(TurboStateEnum::Missing, $status->state);
    }

    #[Test]
    public function aHostUpstreamBuildsNothingForIsNotMissingTurbo(): void
    {
        $this->factory->processes->willSucceed(self::NOT_LOADED);

        $status = $this->probe($this->unshippedHost())->status($this->factory->context(), self::WRAPPER);

        self::assertSame(TurboStateEnum::NotBuiltForHost, $status->state);
    }

    /**
     * A config error stops diagnose as it stops analyse; the lane's own run reports that. What a
     * failed diagnose printed is not its report, whatever Turbo lines it holds.
     */
    #[Test]
    public function aDiagnoseThatFailsLeavesTheStateUnknown(): void
    {
        $this->factory->processes->willFail(1, "Turbo extension: enabled (version 6351afb)\nInvalid configuration:\nUnexpected item 'parameters › nope'.\n");

        $status = $this->probe($this->shippedHost())->status($this->factory->context(), self::WRAPPER);

        self::assertSame(TurboStateEnum::Unknown, $status->state);
    }

    /**
     * A warm `-p` run reuses what diagnose printed and starts no PHPStan; whether a
     * missing Turbo is a fault is still judged against the host and the manifest on every run.
     */
    #[Test]
    public function aCachedProbeReusesDiagnoseOnAWarmPathRun(): void
    {
        $wrapper = $this->factory->project->write('var/qa/phpstan_logs/phpstan-parallel.neon', "parameters:\n    level: 1\n");
        $runtime = \Safe\json_encode(['binary' => \PHP_BINARY, 'ini' => false, 'scanned' => false]);
        $this->factory->processes->willSucceed($runtime)->willSucceed(self::NOT_LOADED)->willSucceed($runtime);
        $context = $this->factory->context($this->factory->builder(specifiedPath: 'src/Kernel.php')->build());

        $cold = $this->probe($this->shippedHost())->status($context, $wrapper);
        $warm = $this->probe($this->unshippedHost())->status($context, $wrapper);

        self::assertSame(TurboStateEnum::Missing, $cold->state);
        self::assertSame(TurboStateEnum::NotBuiltForHost, $warm->state);
        $diagnoses = array_filter($this->factory->processes->specs, static fn (ProcessSpecDto $spec): bool => \in_array('diagnose', $spec->command, true));
        self::assertCount(1, $diagnoses, 'the warm run starts no diagnose');
    }

    private function probe(TurboPlatform $platform): DiagnoseTurboProbe
    {
        return new DiagnoseTurboProbe($platform);
    }

    /** glibc x86_64 on 8.5, a host the committed manifest pins a build for. */
    private function shippedHost(): TurboPlatform
    {
        return new TurboPlatform('Linux', 'x86_64', 'gnu', '8.5', false);
    }

    /** Windows on x86_64, a host upstream builds no Turbo for. */
    private function unshippedHost(): TurboPlatform
    {
        return new TurboPlatform('Windows', 'AMD64', '', '8.5', false);
    }

    private function indexOf(string $argument, string ...$command): int
    {
        $index = array_search($argument, $command, true);
        self::assertIsInt($index, $argument . ' is not in the command');

        return $index;
    }
}
