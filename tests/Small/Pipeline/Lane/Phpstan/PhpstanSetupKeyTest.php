<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use Closure;
use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanSetupKey;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * What the answers of dump-parameters and diagnose depend on, as one digest each. A key changes
 * whenever an input does; where the key cannot see an input it is null, and the probe is asked.
 *
 * @internal
 */
#[CoversClass(PhpstanSetupKey::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\InstalledPhpstanExtensions::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class PhpstanSetupKeyTest extends TestCase
{
    private const string CONFIG = 'qaConfig/phpstan.neon';

    private const string INCLUDED = 'qaConfig/included.neon';

    private const string WRAPPER = 'var/qa/phpstan_logs/phpstan-parallel.neon';

    private const string PHARS = 'phars';

    private const string TURBO_BINARY = 'phars/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so';

    private const string INI = 'ini/php.ini';

    private const string SCANNED = 'ini/conf.d/20-x.ini';

    private const string AUTOLOAD = 'detector/vendor/autoload.php';

    private ContextFactory $factory;

    /** @var array<string, mixed> what the PHP the probes run on reports about itself */
    private array $runtime;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(self::CONFIG, "includes:\n    - included.neon\n\nparameters:\n    level: max\n");
        $this->factory->project->write(self::INCLUDED, "parameters:\n    paths: [src]\n");
        $this->factory->project->write(self::WRAPPER, \sprintf("includes:\n    - %s/%s\n", $this->factory->project->path, self::CONFIG));
        $this->factory->project->write(self::PHARS . '/phpstan.phar', 'phar bytes');
        $this->factory->project->write(self::PHARS . '/turbo-ext.json', '{"phpstan":"2.3.1"}');
        $this->factory->project->write(self::TURBO_BINARY, 'thin library');
        $this->factory->project->write(self::INI, "memory_limit=1G\n");
        $this->factory->project->write(self::SCANNED, "extension=x\n");
        $this->factory->project->write(self::AUTOLOAD, $this->phpOpen());

        $this->runtime = [
            'binary'  => \PHP_BINARY,
            'version' => '8.5.11',
            'ini'     => $this->factory->project->path . '/' . self::INI,
            'scanned' => $this->factory->project->path . '/' . self::SCANNED . ",\n",
            'turbo'   => false,
        ];
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function theSameInputsGiveTheSameKeys(): void
    {
        self::assertSame($this->dumpKey(), $this->dumpKey());
        self::assertSame($this->diagnoseKey(), $this->diagnoseKey());
        self::assertNotSame($this->dumpKey(), $this->diagnoseKey(), 'the two answers are kept apart');
    }

    /** The dump key needs no process: everything it covers is a file this process reads. */
    #[Test]
    public function theDumpKeyStartsNoProcess(): void
    {
        $this->dumpKey();

        self::assertSame([], $this->factory->processes->specs);
    }

    /** The diagnose key asks the PHP that runs PHPStan about itself, as the lane starts PHPStan: Xdebug off, from the project root. */
    #[Test]
    public function theDiagnoseKeyAsksThePhpThatRunsPhpstan(): void
    {
        $this->diagnoseKey();

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(['/usr/bin/php', '-d', 'display_errors=stderr', '-r', PhpstanSetupKey::RUNTIME_PROBE], $spec->command);
        self::assertSame(['XDEBUG_MODE' => 'off'], $spec->env);
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertFalse($spec->streamOutput);
    }

    #[Test]
    public function aChangedPharChangesBothKeys(): void
    {
        $this->assertBothKeysChangeWhen(fn (): string => $this->factory->project->write(self::PHARS . '/phpstan.phar', 'other phar bytes'));
    }

    #[Test]
    public function aChangedConfigChangesBothKeys(): void
    {
        $this->assertBothKeysChangeWhen(fn (): string => $this->factory->project->write(self::CONFIG, "includes:\n    - included.neon\n\nparameters:\n    level: 8\n"));
    }

    #[Test]
    public function aChangedIncludedFileChangesBothKeys(): void
    {
        $this->assertBothKeysChangeWhen(fn (): string => $this->factory->project->write(self::INCLUDED, "parameters:\n    tmpDir: /elsewhere\n"));
    }

    /** phpstan/extension-installer hands PHPStan every installed extension's NEON, which can set parameters too. */
    #[Test]
    public function aChangedExtensionConfigChangesBothKeys(): void
    {
        $this->factory->project->write('vendor/phpstan/extension-installer/src/GeneratedConfig.php', <<<'PHP_WRAP'
            <?php
            namespace PHPStan\ExtensionInstaller;
            final class GeneratedConfig
            {
                public const EXTENSIONS = ['acme/ext' => ['relative_install_path' => '../../../acme/ext', 'extra' => ['includes' => ['extension.neon']]]];
            }
            PHP_WRAP);
        $this->factory->project->write('vendor/acme/ext/extension.neon', "parameters:\n    level: 1\n");

        $this->assertBothKeysChangeWhen(fn (): string => $this->factory->project->write('vendor/acme/ext/extension.neon', "parameters:\n    tmpDir: /ext\n"));
    }

    #[Test]
    public function aChangedAutoloadFileChangesTheDumpKey(): void
    {
        $before = $this->dumpKey(self::AUTOLOAD);
        $this->factory->project->write(self::AUTOLOAD, $this->phpOpen() . "require 'x.php';\n");

        self::assertNotSame($before, $this->dumpKey(self::AUTOLOAD));
        self::assertNotSame($this->dumpKey(), $this->dumpKey(self::AUTOLOAD), 'with and without an autoload file are different questions');
    }

    #[Test]
    public function aChangedWrapperChangesTheDiagnoseKey(): void
    {
        $before = $this->diagnoseKey();
        $this->factory->project->write(self::WRAPPER, \sprintf("includes:\n    - %s/%s\n\nparameters:\n    parallel:\n        maximumNumberOfProcesses: 3\n", $this->factory->project->path, self::CONFIG));

        self::assertNotSame($before, $this->diagnoseKey());
    }

    /** #98: a Turbo binary replaced in place, or its core arriving, changes whether PHPStan loads it. */
    #[Test]
    public function aChangedOrAddedTurboFileChangesTheDiagnoseKey(): void
    {
        $before = $this->diagnoseKey();
        $this->factory->project->write(self::TURBO_BINARY, 'thin library, rebuilt');
        $replaced = $this->diagnoseKey();
        $this->factory->project->write(self::PHARS . '/turbo-ext/linux-gnu-x86_64/phpstan_turbo_core.so', 'core');

        self::assertNotSame($before, $replaced);
        self::assertNotSame($replaced, $this->diagnoseKey());
    }

    #[Test]
    public function aChangedTurboManifestChangesTheDiagnoseKey(): void
    {
        $before = $this->diagnoseKey();
        $this->factory->project->write(self::PHARS . '/turbo-ext.json', '{"phpstan":"2.3.2"}');

        self::assertNotSame($before, $this->diagnoseKey());
    }

    /** Another PHP version, or Turbo loaded through php.ini (#92), is another runtime. */
    #[Test]
    public function aChangedRuntimeChangesTheDiagnoseKey(): void
    {
        $before                   = $this->diagnoseKey();
        $this->runtime['turbo']   = '28c29b8';
        $loaded                   = $this->diagnoseKey();
        $this->runtime['version'] = '8.5.12';

        self::assertNotSame($before, $loaded);
        self::assertNotSame($loaded, $this->diagnoseKey());
    }

    #[Test]
    public function aChangedIniFileChangesTheDiagnoseKey(): void
    {
        $before = $this->diagnoseKey();
        $this->factory->project->write(self::SCANNED, "extension=x\nextension=/opt/phpstan_turbo.so\n");
        $scanned = $this->diagnoseKey();
        $this->factory->project->write(self::INI, "memory_limit=2G\n");

        self::assertNotSame($before, $scanned);
        self::assertNotSame($scanned, $this->diagnoseKey());
    }

    /** The PHP binary itself, rebuilt or swapped under the same version string. */
    #[Test]
    public function aChangedPhpBinaryChangesTheDiagnoseKey(): void
    {
        $binary                  = $this->factory->project->write('bin/php', 'one build');
        $this->runtime['binary'] = $binary;
        $before                  = $this->diagnoseKey();
        $this->factory->project->write('bin/php', 'another build');

        self::assertNotSame($before, $this->diagnoseKey());
    }

    /** No ini file at all is a runtime too, and a valid one. */
    #[Test]
    public function aRuntimeWithNoIniFilesHasAKey(): void
    {
        $this->runtime['ini']     = false;
        $this->runtime['scanned'] = false;

        self::assertNotNull($this->diagnoseKey());
    }

    /** `%env.X%` reads the environment, which the key does not cover. */
    #[Test]
    public function aConfigThatReadsTheEnvironmentHasNoKey(): void
    {
        $this->factory->project->write(self::INCLUDED, "parameters:\n    tmpDir: %env.CI_CACHE%/phpstan\n");

        self::assertNull($this->dumpKey());
        self::assertNull($this->diagnoseKey());
    }

    /** PHPStan runs a PHP configuration file; the walk does not read it, so what it sets is unknown. */
    #[Test]
    public function aConfigThatIncludesAPhpFileHasNoKey(): void
    {
        $this->factory->project->write('qaConfig/dynamic.php', $this->phpOpen() . "return ['parameters' => []];\n");
        $this->factory->project->write(self::INCLUDED, "includes:\n    - dynamic.php\n");

        self::assertNull($this->dumpKey());
        self::assertNull($this->diagnoseKey());
    }

    #[Test]
    public function aConfigWithAnIncludeTheWalkCannotFollowHasNoKey(): void
    {
        $this->factory->project->write(self::INCLUDED, "includes:\n    - %rootDir%/../../x.neon\n    - %projectDir%/y.neon\n");

        self::assertNull($this->dumpKey());
    }

    #[Test]
    public function aMissingConfigHasNoKey(): void
    {
        self::assertNull(new PhpstanSetupKey()->forDumpParameters($this->context(), $this->factory->project->path . '/qaConfig/absent.neon', null));
    }

    #[Test]
    public function aMissingAutoloadFileHasNoKey(): void
    {
        self::assertNull($this->dumpKey('detector/absent.php'));
    }

    #[Test]
    public function aRuntimeThatCannotAnswerHasNoKey(): void
    {
        $this->factory->processes->willFail(255, 'PHP Fatal error');

        self::assertNull(new PhpstanSetupKey()->forDiagnose($this->context(), $this->factory->project->path . '/' . self::WRAPPER));
    }

    #[Test]
    public function aRuntimeAnswerThatIsNotUsableHasNoKey(): void
    {
        foreach (['not json', '"a string"', '{"version":"8.5.11"}', '{"binary":"/nowhere/php","ini":false,"scanned":false}', '{"binary":"' . \PHP_BINARY . '","ini":7,"scanned":false}'] as $answer) {
            $this->factory->processes->willSucceed($answer);

            self::assertNull(new PhpstanSetupKey()->forDiagnose($this->context(), $this->factory->project->path . '/' . self::WRAPPER), $answer);
        }
    }

    /** An ini file the runtime names but this process cannot read is an input the key cannot see. */
    #[Test]
    public function anUnreadableIniFileHasNoKey(): void
    {
        $this->runtime['scanned'] = $this->factory->project->path . '/ini/conf.d/gone.ini,';

        self::assertNull($this->diagnoseKey());
    }

    private function assertBothKeysChangeWhen(Closure $change): void
    {
        $dump     = $this->dumpKey();
        $diagnose = $this->diagnoseKey();
        $change();

        self::assertNotSame($dump, $this->dumpKey());
        self::assertNotSame($diagnose, $this->diagnoseKey());
    }

    private function dumpKey(?string $autoload = null): ?string
    {
        $root = $this->factory->project->path;

        return new PhpstanSetupKey()->forDumpParameters($this->context(), $root . '/' . self::CONFIG, null === $autoload ? null : $root . '/' . $autoload);
    }

    private function diagnoseKey(): ?string
    {
        $this->factory->processes->willSucceed(\Safe\json_encode($this->runtime));

        return new PhpstanSetupKey()->forDiagnose($this->context(), $this->factory->project->path . '/' . self::WRAPPER);
    }

    private function context(): ToolContext
    {
        $paths = $this->factory->paths(pharDir: $this->factory->project->path . '/' . self::PHARS);

        return $this->factory->context($this->factory->builder(paths: $paths)->build());
    }

    private function phpOpen(): string
    {
        return "<?php\n";
    }
}
