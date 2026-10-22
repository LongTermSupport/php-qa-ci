<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use Iterator;
use LTS\PHPQA\Pipeline\Config\DependencyFreshnessPatterns;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\ComposerLock;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\UpdateDryRunOutput;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshnessTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The dependency freshness check (issue #292): a locked package matching the
 * configured patterns that `composer update` would move fails the run; an
 * up-to-date or pinned lock passes in silence; a check that cannot reach the
 * package source is skipped by name, never passed in silence (Owner
 * decision on #292). Composer's answers are the ones a real
 * `composer update --dry-run` printed for a branch-constraint lock.
 *
 * @internal
 */
#[CoversClass(DependencyFreshnessTool::class)]
#[CoversClass(ComposerLock::class)]
#[CoversClass(UpdateDryRunOutput::class)]
#[CoversClass(StaleDependencyDto::class)]
#[CoversClass(DependencyFreshnessPatterns::class)]
#[UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(QaConfigBuilder::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class DependencyFreshnessToolTest extends TestCase
{
    private const string COMPOSER = '/opt/fixture/bin/composer';

    private const string SELF = 'lts/php-qa-ci';

    private const string LOCKED = 'dev-php8.5 93f1ae4';

    private const string AVAILABLE = 'dev-php8.5 606a9f6';

    private const string ACME_ONE = 'acme/one';

    private const string ACME_TWO = 'acme/two';

    private const string ACME_GLOB = 'acme/*';

    private const string PSR_LOG = 'psr/log';

    private const string VENDORX_WANTED = 'vendorx/wanted';

    private const string LOCK_FILE = 'composer.lock';

    private const string SKIP_LINE = 'SKIPPED dependencyFreshness (phpqaci.dependencyFreshness): could not determine whether the locked dependencies are up to date';

    private const string NOT_VERIFIED = 'composer.lock was NOT verified for: lts/php-qa-ci.';

    // What `composer update lts/php-qa-ci --with-dependencies --dry-run` printed for a lock one commit behind its branch.
    private const string STALE_ANSWER = <<<'OUT'
        Loading composer repositories with package information
        Updating dependencies
        Lock file operations: 0 installs, 1 update, 0 removals
          - Upgrading lts/php-qa-ci (dev-php8.5 93f1ae4 => dev-php8.5 606a9f6)
        Installing dependencies from lock file (including require-dev)
        Package operations: 0 installs, 1 update, 0 removals
          - Upgrading lts/php-qa-ci (dev-php8.5 93f1ae4 => dev-php8.5 606a9f6)

        OUT;

    // What it printed once the lock held the branch head.
    private const string CURRENT_ANSWER = <<<'OUT'
        Loading composer repositories with package information
        Updating dependencies
        Nothing to modify in lock file
        Installing dependencies from lock file (including require-dev)
        Nothing to install, update or remove

        OUT;

    // What it printed, exit 1, once the package's repository had gone.
    private const string UNREACHABLE_ANSWER = <<<'OUT'
        Loading composer repositories with package information

        In VcsRepository.php line 193:

          No driver found to handle VCS repository /srv/gone/php-qa-ci


        update [--with WITH] [--prefer-source] [--dry-run] [--] [<packages>...]

        OUT;

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
    public function aLockBehindItsBranchHeadFailsListingTheLockedAndAvailableVersions(): void
    {
        $this->lock(self::SELF, self::PSR_LOG);
        $this->factory->processes->willSucceed(self::STALE_ANSWER);

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 locked package is behind what composer update would install: lts/php-qa-ci', $result->summary);
        self::assertStringContainsString("Dependencies are out of date. `composer update` would install newer versions of:\n\n  - lts/php-qa-ci (locked dev-php8.5 93f1ae4, available dev-php8.5 606a9f6)\n", $printed);
        self::assertSame(1, substr_count($printed, '  - lts/php-qa-ci (locked'), 'the lock and package operations name it once');
        self::assertStringContainsString('Run `composer update`, then run QA again.', $printed);
        self::assertStringContainsString('Do NOT pin', $printed);
        self::assertStringContainsString("\n🪪  phpqaci.dependencyFreshness  (vendor/bin/rule-doc phpqaci.dependencyFreshness)\n", $printed);
    }

    #[Test]
    public function theCheckAsksComposerForADryRunOfTheMatchingLockedPackagesOnly(): void
    {
        $this->lock(self::SELF, self::PSR_LOG);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $this->tool()->run($this->factory->context());

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-d', 'display_errors=stderr', '-f', self::COMPOSER, '--', 'update', self::SELF, '--with-dependencies', '--dry-run', '--no-interaction', '--no-ansi', '--no-scripts', '--no-progress', '--no-audit'],
            $spec->command,
        );
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertFalse($spec->streamOutput, 'an up-to-date lock is checked in silence');
        self::assertSame((float)DependencyFreshnessTool::TIMEOUT_SECONDS, $spec->timeout);
        self::assertSame('0', $spec->env['GIT_TERMINAL_PROMPT'] ?? null, 'a credential prompt cannot hang the run');
        self::assertSame('1', $spec->env['COMPOSER_NO_INTERACTION'] ?? null);
    }

    #[Test]
    public function anUpToDateLockPassesWithoutOutput(): void
    {
        $this->lock(self::SELF);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
    }

    /**
     * The pin is the one escape hatch: with the commit fixed in composer.json,
     * `composer update` has nothing newer to install, and the check takes
     * Composer's word for it rather than comparing against the branch head.
     */
    #[Test]
    public function aPinToTheLockedCommitPasses(): void
    {
        $this->factory->project->write('composer.json', "{\n  \"name\": \"fixture/ctx\",\n  \"type\": \"project\",\n  \"require\": {\"lts/php-qa-ci\": \"dev-php8.5#93f1ae4\"}\n}\n");
        $this->lock(self::SELF);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function anUnreachableSourceIsSkippedByNameNotFailedAndNotPassedInSilence(): void
    {
        $this->lock(self::SELF);
        $this->factory->processes->willFail(1, self::UNREACHABLE_ANSWER);

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE, $printed);
        self::assertStringContainsString('composer failed (exit 1): No driver found to handle VCS repository /srv/gone/php-qa-ci', $printed);
        self::assertStringContainsString(self::NOT_VERIFIED, $printed);
        self::assertStringNotContainsString('phpqaci.dependencyFreshness  (vendor/bin/rule-doc', $printed, 'a skip is not a failure');
    }

    /**
     * Composer falls back to its cache when packagist cannot be reached and
     * still exits 0: an answer from a stale cache is not an answer.
     */
    #[Test]
    public function aDryRunThatFellBackToAStaleCacheIsSkippedByName(): void
    {
        $this->lock(self::SELF);
        $this->factory->processes->willSucceed(
            "Loading composer repositories with package information\n"
            . "https://repo.packagist.org could not be fully loaded (curl error 6 while downloading https://repo.packagist.org/packages.json: Could not resolve host: repo.packagist.org), package information was loaded from the local cache and may be out of date\n"
            . "Updating dependencies\nNothing to modify in lock file\n",
        );

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE, $printed);
        self::assertStringContainsString('https://repo.packagist.org could not be fully loaded', $printed);
        self::assertStringContainsString(self::NOT_VERIFIED, $printed);
    }

    /**
     * A pass rests on a positive sign that Composer answered. A `composer`
     * on the PATH that is a shell shim makes `php -f` print the script text
     * and exit 0: nothing asked Composer, so nothing was verified.
     */
    #[Test]
    #[DataProvider('provideOutputThatIsNoAnswerFromComposer')]
    public function aRunThatExitedZeroWithoutAnAnswerFromComposerIsSkippedByName(string $printed, string $quoted): void
    {
        $this->lock(self::SELF);
        $this->factory->processes->willSucceed($printed);

        $result  = $this->tool()->run($this->factory->context());
        $output  = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE, $output);
        self::assertStringContainsString('composer gave no recognisable answer', $output);
        self::assertStringContainsString($quoted, $output);
        self::assertStringContainsString(self::NOT_VERIFIED, $output);
        self::assertStringNotContainsString('phpqaci.dependencyFreshness  (vendor/bin/rule-doc', $output, 'a skip is not a failure');
    }

    /** @return Iterator<string, array{string, string}> */
    public static function provideOutputThatIsNoAnswerFromComposer(): Iterator
    {
        yield 'a shell shim printed as inline HTML' => ["exec /usr/bin/composer \"\$@\"\n", 'exec /usr/bin/composer "$@"'];
        yield 'a shim with its shebang line' => ["#!/bin/sh\nexec /usr/bin/composer \"\$@\"\n", '#!/bin/sh'];
        yield 'nothing at all' => ['', 'nothing'];
        yield 'only blank lines' => ["\n \n", 'nothing'];
        yield 'unrelated text' => ["<html><body>502 Bad Gateway</body></html>\n", '502 Bad Gateway'];
        yield 'only the loading line, no answer after it' => ["Loading composer repositories with package information\n", 'Loading composer repositories'];
    }

    /**
     * Each of these is Composer's own answer (see the samples in the reader
     * test) and none of them upgrades a matching package.
     */
    #[Test]
    #[DataProvider('provideComposerAnswersWithNothingStale')]
    public function anAnswerFromComposerWithNoMatchingUpgradePassesInSilence(string $printed): void
    {
        $this->lock(self::SELF, self::PSR_LOG);
        $this->factory->processes->willSucceed($printed);

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
    }

    /** @return Iterator<string, array{string}> */
    public static function provideComposerAnswersWithNothingStale(): Iterator
    {
        yield 'nothing to modify in the lock file' => ["Loading composer repositories with package information\nUpdating dependencies\nNothing to modify in lock file\n"];
        yield 'nothing to modify, without the loading line' => ["Nothing to modify in lock file\n"];
        yield 'lock file operations that upgrade only a package no pattern matches' => ["Updating dependencies\nLock file operations: 0 installs, 1 update, 0 removals\n  - Upgrading psr/log (3.0.0 => 3.0.2)\n"];
        yield 'lock file operations that only downgrade' => ["Lock file operations: 0 installs, 1 update, 0 removals\n  - Downgrading lts/php-qa-ci (dev-php8.5 93f1ae4 => 1.2.0)\n"];
        yield 'updating dependencies and nothing after it' => ["Loading composer repositories with package information\nUpdating dependencies\n"];
        yield 'the answer with colour codes' => ["\e[32mLoading composer repositories with package information\e[39m\n\e[32mUpdating dependencies\e[39m\nNothing to modify in lock file\n"];
    }

    /** `--no-ansi` is always passed, but a stale lock must not pass on a coloured answer. */
    #[Test]
    public function aColouredAnswerIsReadLikeAPlainOne(): void
    {
        $this->lock(self::SELF);
        $this->factory->processes->willSucceed(
            "\e[32mUpdating dependencies\e[39m\n"
            . "\e[32mLock file operations: 0 installs, 1 update, 0 removals\e[39m\n"
            . "  - Upgrading \e[32mlts/php-qa-ci\e[39m (\e[33mdev-php8.5 93f1ae4\e[39m => \e[33mdev-php8.5 606a9f6\e[39m)\n",
        );

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('  - lts/php-qa-ci (locked dev-php8.5 93f1ae4, available dev-php8.5 606a9f6)', $this->factory->output->fetch());
    }

    #[Test]
    public function aDryRunThatTimesOutIsSkippedByName(): void
    {
        $this->lock(self::SELF);
        $this->factory->processes->willRun(static fn (ProcessSpecDto $spec): ProcessResultDto => throw new ProcessTimedOutException(new Process($spec->command, timeout: $spec->timeout), ProcessTimedOutException::TYPE_GENERAL));

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE, $printed);
        self::assertStringContainsString(\sprintf('composer did not answer within %d seconds', DependencyFreshnessTool::TIMEOUT_SECONDS), $printed);
    }

    #[Test]
    public function aProjectVendorGlobMatchesOnlyThatVendorsPackages(): void
    {
        $this->lockWithDev(self::ACME_TWO, self::SELF, self::ACME_ONE, 'acmeco/three', 'other/acme', 'lts/other');
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame([self::SELF, self::ACME_ONE, self::ACME_TWO], $this->packagesAskedAbout());
    }

    #[Test]
    public function aProjectCanNameOnePackageOfAnotherVendorWithoutItsSiblings(): void
    {
        $this->lock(self::SELF, self::ACME_ONE, self::VENDORX_WANTED, 'vendorx/unwanted');
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB, self::VENDORX_WANTED)->build()));

        self::assertSame([self::SELF, self::ACME_ONE, self::VENDORX_WANTED], $this->packagesAskedAbout());
    }

    #[Test]
    public function aProjectOverrideCannotDropPhpQaCisOwnEntry(): void
    {
        $this->lock(self::SELF, self::ACME_ONE);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $config = $this->factory->builder()
            ->withDependencyFreshnessPatterns('vendorx/*')
            ->withOnlyDependencyFreshnessPatterns(self::ACME_GLOB)
            ->build()
        ;
        $this->tool()->run($this->factory->context($config));

        self::assertSame([self::SELF, self::ACME_ONE], $this->packagesAskedAbout());
    }

    #[Test]
    public function aStaleProjectPackageIsListedBesidePhpQaCi(): void
    {
        $this->lock(self::SELF, self::ACME_ONE);
        $this->factory->processes->willSucceed(
            "Lock file operations: 0 installs, 3 updates, 0 removals\n"
            . "  - Upgrading acme/one (1.2.0 => 1.3.1)\n"
            . "  - Upgrading lts/php-qa-ci (dev-php8.5 93f1ae4 => dev-php8.5 606a9f6)\n"
            . "  - Upgrading psr/log (3.0.0 => 3.0.2)\n",
        );

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('2 locked packages are behind what composer update would install: acme/one, lts/php-qa-ci', $result->summary);
        self::assertStringContainsString("  - acme/one (locked 1.2.0, available 1.3.1)\n  - lts/php-qa-ci (locked " . self::LOCKED . ', available ' . self::AVAILABLE . ")\n", $printed);
        self::assertStringNotContainsString(self::PSR_LOG, $printed, "a dependency the patterns do not match is not this check's business");
    }

    /**
     * php-qa-ci's own repository is lts/php-qa-ci as the root package, never a
     * locked one: there is nothing to ask Composer about, and nothing to say.
     */
    #[Test]
    public function aLockWithNoMatchingPackageRunsNothingAndPassesInSilence(): void
    {
        $this->lock(self::PSR_LOG, 'symfony/process');

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function aProjectWithNoLockFileIsSkippedByName(): void
    {
        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
        self::assertStringContainsString(self::SKIP_LINE . ': there is no composer.lock.', $printed);
    }

    #[Test]
    public function anUnreadableLockFileFailsTheLane(): void
    {
        $this->factory->project->write(self::LOCK_FILE, '{not json');

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('composer.lock is not valid JSON', $result->summary);
    }

    private function tool(): DependencyFreshnessTool
    {
        return new DependencyFreshnessTool(self::COMPOSER);
    }

    private function lock(string ...$packages): void
    {
        $this->lockWithDev(null, ...$packages);
    }

    /** A lock holding $packages under `packages` and $devPackage, when given, under `packages-dev`. */
    private function lockWithDev(?string $devPackage, string ...$packages): void
    {
        $entry = static fn (string $name): array => ['name' => $name, 'version' => 'dev-php8.5', 'source' => ['type' => 'git', 'reference' => '93f1ae4']];
        $this->factory->project->write(self::LOCK_FILE, \Safe\json_encode([
            'packages'     => array_map($entry, array_values($packages)),
            'packages-dev' => null === $devPackage ? [] : [$entry($devPackage)],
        ], JSON_PRETTY_PRINT));
    }

    /** @return list<string> the package names the dry run was asked about */
    private function packagesAskedAbout(): array
    {
        $command   = $this->factory->processes->lastSpec()->command;
        $arguments = \array_slice($command, (int)array_search('update', $command, true) + 1);

        return array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));
    }
}
