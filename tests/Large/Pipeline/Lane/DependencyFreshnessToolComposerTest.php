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
    private const string PACKAGE = 'lts/php-qa-ci';

    private const string MIXED = 'dev-php8.5@dev || ^1.0';

    private const string BRANCH_BEHIND_LINE = '  - lts/php-qa-ci (locked dev-php8.5 %s, available dev-php8.5 %s)';

    private const string PACKAGE_JSON = "{\"name\": \"lts/php-qa-ci\", \"type\": \"library\"}\n";

    private const string FIRST_RELEASE = '1.0.0';

    private const string SECOND_RELEASE = '1.1.0';

    private const string PREFER_STABLE = '--prefer-stable';

    private const string BEHIND_THE_SECOND_RELEASE = '  - lts/php-qa-ci (locked 1.0.0, available 1.1.0)';

    /** The local git repository php-qa-ci is served from. */
    private GitSandbox $sandbox;

    /** The project that requires php-qa-ci. */
    private string $app;

    /** How many commits the branch has been moved on by, so each commit changes the file. */
    private int $moves = 0;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create(['composer.json' => self::PACKAGE_JSON]);
        $this->app     = $this->sandbox->root->mkdir('app');
        $this->writeAppComposerJson('dev-php8.5');
        $this->update();
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
            \sprintf(self::BRANCH_BEHIND_LINE, substr($locked, 0, 7), substr($head, 0, 7)),
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

    /**
     * `php -f` on a shell script prints the script and exits 0: a `composer`
     * that is a shim never answered, so the lock was not verified.
     */
    #[Test]
    public function aComposerThatIsAShellShimIsSkippedByNameNotPassed(): void
    {
        $this->sandbox->root->write('shim/composer', "#!/bin/sh\nexec " . $this->composerBinary() . " \"\$@\"\n");

        [$result, $printed] = $this->check($this->sandbox->root->path . '/shim/composer');

        self::assertSame(ToolOutcomeEnum::Skipped, $result, $printed);
        self::assertStringContainsString('composer gave no recognisable answer', $printed);
        self::assertStringContainsString('composer.lock was NOT verified for: lts/php-qa-ci.', $printed);
    }

    /**
     * A project whose constraint tracks tags is judged against the newest tag
     * the constraint allows, and the remedy the lane prints really clears it.
     */
    #[Test]
    public function aTagTrackingLockBehindANewerTagInsideTheConstraintFailsWithARemedyThatWorks(): void
    {
        $this->lockOnTag('^1.0');
        $this->release(self::SECOND_RELEASE);

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Failed, $result, $printed);
        self::assertStringContainsString(self::BEHIND_THE_SECOND_RELEASE, $printed);
        self::assertStringContainsString('composer update lts/php-qa-ci --with-dependencies --prefer-stable', $printed);

        $this->applyTheRemedy();

        [$after, $printedAfter] = $this->check();
        self::assertSame(ToolOutcomeEnum::Passed, $after, $printedAfter);
    }

    #[Test]
    public function aTagTrackingLockWhoseNewestTagIsOutsideTheConstraintPasses(): void
    {
        $this->lockOnTag('~1.0.0');
        $this->release(self::SECOND_RELEASE);

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Passed, $result, $printed);
    }

    /** A tag lock is never judged against the branch head, whatever the constraint admits. */
    #[Test]
    public function aTagTrackingLockUnderAConstraintThatAlsoAdmitsTheBranchIsJudgedByTagsOnly(): void
    {
        $this->lockOnTag('*');
        $this->advanceTheBranch();

        [$result, $printed] = $this->check();
        self::assertSame(ToolOutcomeEnum::Passed, $result, $printed);

        $this->release(self::SECOND_RELEASE);

        [$stale, $printedStale] = $this->check();
        self::assertSame(ToolOutcomeEnum::Failed, $stale, $printedStale);
        self::assertStringContainsString(self::BEHIND_THE_SECOND_RELEASE, $printedStale);

        $this->applyTheRemedy();

        [$after, $printedAfter] = $this->check();
        self::assertSame(ToolOutcomeEnum::Passed, $after, $printedAfter);
    }

    /** The rejected `--prefer-stable` attempt failed this one: a branch-head lock under `*`, with a tag available. */
    #[Test]
    public function aBranchHeadLockUnderAConstraintThatAlsoAdmitsTagsPasses(): void
    {
        $this->tag(self::FIRST_RELEASE);
        $this->writeAppComposerJson('*');

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Passed, $result, $printed);
    }

    #[Test]
    public function aBranchLockBehindItsHeadUnderAConstraintThatAlsoAdmitsTagsFailsAgainstTheBranch(): void
    {
        $locked = $this->lockedReference();
        $this->tag(self::FIRST_RELEASE);
        $this->writeAppComposerJson('*');
        $head = $this->advanceTheBranch();

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Failed, $result, $printed);
        self::assertStringContainsString(
            \sprintf(self::BRANCH_BEHIND_LINE, substr($locked, 0, 7), substr($head, 0, 7)),
            $printed,
        );
    }

    /** Tags that exist do not matter to a project that tracks the branch alone. */
    #[Test]
    public function aBranchTrackingLockAtItsHeadPassesWhateverTagsExist(): void
    {
        $this->tag(self::FIRST_RELEASE);
        $this->release(self::SECOND_RELEASE);
        $this->update();

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Passed, $result, $printed);
    }

    /** A mixed constraint is not judged against tags: a lock at the branch head, ahead of the newest tag, is fresh. */
    #[Test]
    public function aBranchHeadLockAheadOfTheNewestTagUnderABranchOrTagConstraintPasses(): void
    {
        $this->tag(self::FIRST_RELEASE);
        $this->advanceTheBranch();
        $this->update(self::PACKAGE);
        $this->writeAppComposerJson(self::MIXED);

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Passed, $result, $printed);
        self::assertStringNotContainsString('available 1.0.0', $printed);
    }

    /** Behind the head under a mixed constraint: stale, and the remedy never moves the lock back to the tag. */
    #[Test]
    public function aBranchLockBehindItsHeadUnderABranchOrTagConstraintFailsWithARemedyThatDoesNotGoBackwards(): void
    {
        $this->tag(self::FIRST_RELEASE);
        $this->advanceTheBranch();
        $this->update(self::PACKAGE);
        $this->writeAppComposerJson(self::MIXED);
        $locked = $this->lockedReference();
        $head   = $this->advanceTheBranch();

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Failed, $result, $printed);
        self::assertStringContainsString(
            \sprintf(self::BRANCH_BEHIND_LINE, substr($locked, 0, 7), substr($head, 0, 7)),
            $printed,
        );
        self::assertStringContainsString("composer require 'lts/php-qa-ci:dev-php8.5@dev' --with-dependencies", $printed);
        self::assertStringNotContainsString('available 1.0.0', $printed);
        self::assertStringNotContainsString('composer update lts/php-qa-ci', $printed);

        $this->composer('require', '--no-interaction', '--no-progress', '--no-scripts', 'lts/php-qa-ci:dev-php8.5@dev', '--with-dependencies');

        self::assertSame($head, $this->lockedReference());
        [$after, $printedAfter] = $this->check();
        self::assertSame(ToolOutcomeEnum::Passed, $after, $printedAfter);
    }

    /** A tag lock under a mixed constraint is judged by tags, with the branch ahead of them. */
    #[Test]
    public function aTagLockBehindANewerInRangeTagUnderABranchOrTagConstraintFailsWithARemedyThatWorks(): void
    {
        $this->tag(self::FIRST_RELEASE);
        $this->writeAppComposerJson(self::MIXED);
        $this->update(self::PREFER_STABLE);
        $this->release(self::SECOND_RELEASE);
        $this->advanceTheBranch();

        [$result, $printed] = $this->check();

        self::assertSame(ToolOutcomeEnum::Failed, $result, $printed);
        self::assertStringContainsString(self::BEHIND_THE_SECOND_RELEASE, $printed);

        $this->applyTheRemedy();

        [$after, $printedAfter] = $this->check();
        self::assertSame(ToolOutcomeEnum::Passed, $after, $printedAfter);
    }

    /** Lock the app on the release 1.0.0 under $constraint. */
    private function lockOnTag(string $constraint): void
    {
        $this->tag(self::FIRST_RELEASE);
        $this->writeAppComposerJson($constraint);
        $this->update(self::PREFER_STABLE);
    }

    /** What the failure tells a project that follows tags to run. */
    private function applyTheRemedy(): void
    {
        $this->update(self::PACKAGE, '--with-dependencies', self::PREFER_STABLE);
    }

    private function update(string ...$arguments): void
    {
        $this->composer('update', '--no-interaction', '--no-progress', '--no-scripts', ...array_values($arguments));
    }

    /** Tag the work clone's head and push the tag. */
    private function tag(string $name): void
    {
        $this->sandbox->git('tag', $name);
        $this->sandbox->git('push', 'origin', $name);
    }

    /** A new commit on the branch, tagged as a release. */
    private function release(string $name): void
    {
        $this->advanceTheBranch();
        $this->tag($name);
    }

    /** @return array{ToolOutcomeEnum, string} */
    private function check(?string $composer = null): array
    {
        $output = new BufferedOutput();
        $result = new DependencyFreshnessTool($composer ?? $this->composerBinary())->run($this->context($output));

        return [$result->outcome, $output->fetch()];
    }

    /** Commit and push to the branch the app is locked on; returns the new head. */
    private function advanceTheBranch(): string
    {
        $this->sandbox->commit('CHANGELOG.md', \sprintf("# Changes\n\nMove %d\n", ++$this->moves), 'Move the branch on');
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
            'require'           => [self::PACKAGE => $constraint],
            'minimum-stability' => 'dev',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function composer(string ...$args): void
    {
        $process = new Process([\PHP_BINARY, '-d', 'display_errors=stderr', $this->composerBinary(), ...array_values($args)], $this->app, $this->environment());
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
            'COMPOSER_HOME'            => $this->sandbox->root->path . '/composer-home',
            'COMPOSER_CACHE_DIR'       => $this->sandbox->root->path . '/composer-cache',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'XDEBUG_MODE'              => 'off',
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
