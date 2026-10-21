<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshnessTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The freshness check against real Composer and a real branch: php-qa-ci is
 * served from a local git repository (no network), installed on a
 * `dev-php8.5` constraint, and then moved on. The Small tests pin how the lane
 * reads Composer's answer; these prove Composer answers in that shape, and
 * that a pin really does leave `composer update` nothing to install.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class DependencyFreshnessToolComposerTest extends TestCase
{
    private const string PACKAGE_JSON = "{\"name\": \"lts/php-qa-ci\", \"type\": \"library\"}\n";

    private GitSandbox $sandbox;

    private string $app;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create(['composer.json' => self::PACKAGE_JSON]);
        $this->app     = $this->sandbox->root->mkdir('app');
        $this->writeAppComposerJson('dev-php8.5');
        $this->composer('update', '--no-interaction', '--no-progress', '--no-scripts');
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function aLockAtTheBranchHeadPassesWithoutOutput(): void
    {
        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Passed, $result, $printed);
        self::assertStringNotContainsString('Dependencies are out of date', $printed);
        self::assertStringNotContainsString('SKIPPED', $printed);
    }

    #[Test]
    public function aLockBehindTheBranchHeadFailsNamingBothCommits(): void
    {
        $locked = $this->lockedReference();
        $head   = $this->advanceTheBranch();

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Failed, $result, $printed);
        self::assertStringContainsString(
            \sprintf('  - lts/php-qa-ci (locked dev-php8.5 %s, available dev-php8.5 %s)', substr($locked, 0, 7), substr($head, 0, 7)),
            $printed,
        );
    }

    #[Test]
    public function aPinToTheLockedCommitPasses(): void
    {
        $locked = $this->lockedReference();
        $this->advanceTheBranch();
        $this->writeAppComposerJson('dev-php8.5#' . $locked);

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Passed, $result, $printed);
    }

    #[Test]
    public function anUnreachableSourceIsSkippedByName(): void
    {
        TempDir::removeTree($this->sandbox->origin);
        TempDir::removeTree($this->sandbox->root->path . '/composer-cache');

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Skipped, $result, $printed);
        self::assertStringContainsString('SKIPPED dependencyFreshness (phpqaci.dependencyFreshness): could not determine whether the locked dependencies are up to date', $printed);
        self::assertStringContainsString('composer.lock was NOT verified for: lts/php-qa-ci.', $printed);
    }

    /** @return array{ToolOutcomeEnum, string} */
    private function check(): array
    {
        $output = new BufferedOutput();
        $result = new DependencyFreshnessTool($this->composerBinary())->run($this->context($output));

        return [$result->outcome, $output->fetch()];
    }

    /** Commit and push to the branch the app is locked on; returns the new head. */
    private function advanceTheBranch(): string
    {
        $this->sandbox->commit('CHANGELOG.md', "# Changes\n", 'Move the branch on');
        $this->sandbox->git('push', 'origin', GitSandbox::DEFAULT_BRANCH);

        return trim($this->sandbox->git('rev-parse', 'HEAD'));
    }

    private function lockedReference(): string
    {
        $lock      = \Safe\json_decode($this->sandbox->root->read('app/composer.lock'), true);
        $reference = \is_array($lock) && \is_array($lock['packages'] ?? null) && \is_array($lock['packages'][0] ?? null) && \is_array($lock['packages'][0]['source'] ?? null)
            ? $lock['packages'][0]['source']['reference'] ?? null
            : null;
        if (!\is_string($reference)) {
            throw new RuntimeException('the fixture lock holds no source reference for lts/php-qa-ci');
        }

        return $reference;
    }

    private function writeAppComposerJson(string $constraint): void
    {
        $this->sandbox->root->write('app/composer.json', \Safe\json_encode([
            'name'              => 'fixture/app',
            'type'              => 'project',
            'repositories'      => [['type' => 'vcs', 'url' => $this->sandbox->origin]],
            'require'           => ['lts/php-qa-ci' => $constraint],
            'minimum-stability' => 'dev',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function composer(string ...$args): void
    {
        $process = new Process([\PHP_BINARY, $this->composerBinary(), ...array_values($args)], $this->app, $this->environment());
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('composer %s failed: %s', implode(' ', $args), $process->getErrorOutput() . $process->getOutput()));
        }
    }

    private function composerBinary(): string
    {
        return new ExecutableFinder()->find('composer') ?? throw new RuntimeException('composer is not on the PATH');
    }

    /**
     * Git isolated from the host's configuration, and Composer's home and
     * cache inside the sandbox so the fixture repository never reaches the
     * host's cache.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        return [
            ...GitSandbox::environment(),
            'COMPOSER_HOME'           => $this->sandbox->root->path . '/composer-home',
            'COMPOSER_CACHE_DIR'      => $this->sandbox->root->path . '/composer-cache',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'XDEBUG_MODE'             => 'off',
        ];
    }

    private function context(BufferedOutput $output): ToolContext
    {
        $library = \dirname(__DIR__, 4);
        $paths   = new ProjectPathsDto(
            projectRoot: $this->app,
            libraryRoot: $library,
            binDir: $this->app . '/vendor/bin',
            srcDir: $this->app . '/src',
            testsDir: $this->app . '/tests',
            projectConfigDir: $this->app . '/qaConfig',
            varDir: $this->app . '/var/qa',
            cacheDir: $this->app . '/var/qa/cache',
            pharDir: $library . '/vendor-phar',
            configDefaultsDir: $library . '/configDefaults',
        );
        $config = QaConfigBuilder::defaults(
            paths: $paths,
            platform: PlatformEnum::Generic,
            env: new EnvironmentReader([]),
            phpBinPath: \PHP_BINARY,
            xdebugEnabled: false,
            halfCpuThreads: 1,
            ci: true,
            readOnly: true,
            aggregate: true,
            jsonOutput: false,
            agentMode: false,
            singleTool: null,
            specifiedPath: null,
        )->build();
        $environment = $this->environment();
        $real        = new SymfonyProcessRunner($output);
        $processes   = new readonly class($real, $environment) implements ProcessRunnerInterface {
            /** @param array<string, string> $environment */
            public function __construct(private ProcessRunnerInterface $real, private array $environment)
            {
            }

            public function run(ProcessSpecDto $spec): ProcessResultDto
            {
                return $this->real->run(new ProcessSpecDto($spec->command, $spec->cwd, [...$spec->env, ...$this->environment], $spec->timeout, $spec->streamOutput, $spec->lowPriority));
            }
        };

        return new ToolContext(
            config: $config,
            configPaths: new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, $config->platform),
            processes: $processes,
            php: new PhpInvoker($processes, \PHP_BINARY, '1G', $paths->varDir),
            logs: new LogArchiver($output),
            output: $output,
            stdout: $output,
        );
    }
}
