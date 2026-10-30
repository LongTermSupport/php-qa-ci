<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use Iterator;
use LTS\PHPQA\Pipeline\Config\DependencyFreshnessPatterns;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\ComposerLock;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Tracking;
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
#[CoversClass(Tracking::class)]
#[CoversClass(\LTS\PHPQA\Pipeline\Lane\DependencyFreshness\BranchOnlyProject::class)]
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

    private const string COMPOSER_JSON = 'composer.json';

    private const string TAG_LOCK = '1.0.0';

    private const string CARET = '^1.0';

    private const string MAIN = 'dev-main';

    private const string ONE_BEHIND = "Updating dependencies\n  - Upgrading acme/one (dev-main 93f1ae4 => dev-main 606a9f6)\n";

    private const string MAIN_OR_RANGE = 'dev-main || ^1.0';

    private const string PIN_ADVICE = <<<'TXT'
        Do NOT pin a package in composer.json to make this check pass. A pin to a tag or
        a commit leaves `composer update` nothing newer to install, so it is the only way
        past this check, and it is for a compelling reason only, such as a known
        regression in the newest version. Record that reason and its issue link with the
        pin, and remove the pin as soon as the reason is gone.

        TXT;

    private const string NOT_JSON = '{not json';

    private const string UPDATE = 'update';

    private const string NOT_VERIFIED_ONE = 'composer.lock was NOT verified for: acme/one.';

    private const string HEADING = "\nDependencies are out of date. `composer update` would install newer versions of:\n\n";

    private const string PREFER_STABLE = '--prefer-stable';

    private const string RUN_COMPOSER_UPDATE = 'Run `composer update`, then run QA again.';

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

    private const string HELD_BACK = 'held back';

    private const string NO_COOLDOWN = '--no-cooldown';

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
        self::assertStringContainsString(self::RUN_COMPOSER_UPDATE, $printed);
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
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-d', 'display_errors=stderr', '-f', self::COMPOSER, '--', self::UPDATE, self::SELF, '--with-dependencies', '--dry-run', '--no-interaction', '--no-ansi', '--no-scripts', '--no-progress', '--no-audit'],
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
        $this->factory->project->write(self::COMPOSER_JSON, "{\n  \"name\": \"fixture/ctx\",\n  \"type\": \"project\",\n  \"require\": {\"lts/php-qa-ci\": \"dev-php8.5#93f1ae4\"}\n}\n");
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
        $this->factory->project->write(self::LOCK_FILE, self::NOT_JSON);

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('composer.lock is not valid JSON', $result->summary);
    }

    /**
     * A package that follows release tags is asked with `--prefer-stable` and
     * judged by the newest tag in its constraint (issue #345).
     */
    #[Test]
    public function aTagTrackingPackageBehindANewerTagFailsWithAPreferStableRemedy(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willSucceed("Updating dependencies\nLock file operations: 0 installs, 1 update, 0 removals\n  - Upgrading acme/one (1.0.0 => 1.1.0)\n");

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 locked package is behind what composer update would install: acme/one', $result->summary);
        self::assertContains(self::PREFER_STABLE, $this->factory->processes->lastSpec()->command);
        self::assertStringContainsString("  - acme/one (locked 1.0.0, available 1.1.0)\n", $printed);
        self::assertStringContainsString("For the packages that follow release tags, run `composer update acme/one --with-dependencies --prefer-stable`.\n", $printed);
        self::assertStringNotContainsString('follow a branch', $printed);
        self::assertStringNotContainsString('Run `composer update`, then run QA again.', $printed);
        self::assertStringContainsString('Do NOT pin', $printed);
    }

    /** A tag-tracking package is never judged against a branch head, even when Composer would move to one. */
    #[Test]
    public function aTagTrackingPackageComposerWouldMoveToABranchHeadPasses(): void
    {
        $this->project(['acme/one' => '*'], ['acme/one' => self::TAG_LOCK]);
        $this->factory->processes->willSucceed("Updating dependencies\nLock file operations: 0 installs, 1 update, 0 removals\n  - Upgrading acme/one (1.0.0 => dev-main 606a9f6)\n");

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function aBranchTrackingPackageIsNotAskedWithPreferStable(): void
    {
        $this->project(['acme/one' => '*'], ['acme/one' => self::MAIN]);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertNotContains(self::PREFER_STABLE, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function aBranchConstraintIsJudgedAgainstTheBranchEvenWhenTheLockIsOnATag(): void
    {
        $this->project(['acme/one' => self::MAIN], ['acme/one' => self::TAG_LOCK]);
        $this->factory->processes->willSucceed("Updating dependencies\nLock file operations: 0 installs, 1 update, 0 removals\n  - Upgrading acme/one (1.0.0 => dev-main 606a9f6)\n");

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertNotContains(self::PREFER_STABLE, $this->factory->processes->lastSpec()->command);
        self::assertStringContainsString(self::RUN_COMPOSER_UPDATE, $printed);
    }

    #[Test]
    public function branchAndTagPackagesAreAskedSeparatelyAndEachGetsItsOwnRemedy(): void
    {
        $this->project(['acme/one' => self::MAIN, 'acme/two' => self::CARET], ['acme/one' => self::MAIN, 'acme/two' => self::TAG_LOCK]);
        $this->factory->processes
            ->willSucceed(self::ONE_BEHIND)
            ->willSucceed("Updating dependencies\n  - Upgrading acme/two (1.0.0 => 1.2.0)\n")
        ;

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        [$first, $second] = $this->factory->processes->specs;
        self::assertContains(self::ACME_ONE, $first->command);
        self::assertNotContains(self::ACME_TWO, $first->command);
        self::assertNotContains(self::PREFER_STABLE, $first->command);
        self::assertContains(self::ACME_TWO, $second->command);
        self::assertNotContains(self::ACME_ONE, $second->command);
        self::assertContains(self::PREFER_STABLE, $second->command);
        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('2 locked packages are behind what composer update would install: acme/one, acme/two', $result->summary);
        self::assertStringContainsString("For the packages that follow a branch, run `composer update acme/one`.\n", $printed);
        self::assertStringContainsString("For the packages that follow release tags, run `composer update acme/two --with-dependencies --prefer-stable`.\n", $printed);
    }

    /**
     * Composer prefers a tag to a branch head under `dev-main || ^1.0`, so a
     * branch lock is asked under the branch alone, in a throwaway copy that
     * is removed afterwards.
     */
    #[Test]
    public function aBranchLockUnderABranchOrTagConstraintIsAskedUnderTheBranchAloneInAThrowawayCopy(): void
    {
        $this->project(['acme/one' => 'dev-main@dev || ^1.0'], ['acme/one' => self::MAIN]);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertNotContains(self::PREFER_STABLE, $spec->command);
        self::assertContains(self::ACME_ONE, $spec->command);
        self::assertSame('1', $spec->env['COMPOSER_NO_INTERACTION']);
        self::assertMatchesRegularExpression('/^\.qa-freshness-[0-9a-f]{12}\.json$/', $spec->env['COMPOSER']);
        self::assertSame([self::COMPOSER_JSON, self::LOCK_FILE], $this->projectFiles());
    }

    #[Test]
    public function aPackageThatFollowsNoBranchIsNotAskedInACopy(): void
    {
        $this->project(['acme/one' => self::CARET, 'acme/two' => self::MAIN], ['acme/one' => self::TAG_LOCK, 'acme/two' => self::MAIN]);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER)->willSucceed(self::CURRENT_ANSWER);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        foreach ($this->factory->processes->specs as $spec) {
            self::assertArrayNotHasKey('COMPOSER', $spec->env);
        }
    }

    #[Test]
    public function aStaleBranchLockUnderABranchOrTagConstraintIsToldToRequireTheBranchNeverToUpdate(): void
    {
        $this->project(['acme/one' => self::MAIN_OR_RANGE], ['acme/one' => self::MAIN]);
        $this->factory->processes->willSucceed(self::ONE_BEHIND);

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 locked package is behind what composer update would install: acme/one', $result->summary);
        self::assertStringContainsString("  composer require 'acme/one:dev-main' --with-dependencies\n", $printed);
        self::assertStringNotContainsString('--dev', $printed);
        self::assertStringNotContainsString('composer update acme/one', $printed);
        self::assertStringNotContainsString('Run `composer update`, then', $printed);
        self::assertStringContainsString('Then run QA again.', $printed);
        self::assertSame([self::COMPOSER_JSON, self::LOCK_FILE], $this->projectFiles());
    }

    #[Test]
    public function aDevRequirementIsRequiredWithTheDevFlagAndAllThreeRemediesAreGiven(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, \Safe\json_encode([
            'require'     => ['acme/one' => self::MAIN, 'acme/three' => self::CARET],
            'require-dev' => ['acme/two' => 'dev-main@dev || ^1.0'],
        ]));
        $this->factory->project->write(self::LOCK_FILE, \Safe\json_encode([
            'packages'     => [['name' => 'acme/one', 'version' => self::MAIN], ['name' => 'acme/three', 'version' => self::TAG_LOCK]],
            'packages-dev' => [['name' => 'acme/two', 'version' => self::MAIN]],
        ]));
        $this->factory->processes
            ->willSucceed(self::ONE_BEHIND)
            ->willSucceed("Updating dependencies\n  - Upgrading acme/two (dev-main 93f1ae4 => dev-main 606a9f6)\n")
            ->willSucceed("Updating dependencies\n  - Upgrading acme/three (1.0.0 => 1.2.0)\n")
        ;

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertStringContainsString("  composer require --dev 'acme/two:dev-main@dev' --with-dependencies\n", $printed);
        self::assertStringContainsString("For the packages that follow a branch, run `composer update acme/one`.\n", $printed);
        self::assertStringContainsString("For the packages that follow release tags, run `composer update acme/three --with-dependencies --prefer-stable`.\n", $printed);
    }

    #[Test]
    public function aSkippedMixedAskNamesItsPackagesAndStillRemovesTheCopy(): void
    {
        $this->project(['acme/one' => self::MAIN_OR_RANGE], ['acme/one' => self::MAIN]);
        $this->factory->processes->willFail(1, self::UNREACHABLE_ANSWER);

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::NOT_VERIFIED_ONE, $this->factory->output->fetch());
        self::assertSame([self::COMPOSER_JSON, self::LOCK_FILE], $this->projectFiles());
    }

    #[Test]
    public function aTagLockUnderABranchOrTagConstraintIsAskedPreferStableAndJudgedByTags(): void
    {
        $this->project(['acme/one' => self::MAIN_OR_RANGE], ['acme/one' => self::TAG_LOCK]);
        $this->factory->processes->willSucceed("Updating dependencies\n  - Upgrading acme/one (1.0.0 => dev-main 606a9f6)\n");

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertContains(self::PREFER_STABLE, $spec->command);
        self::assertArrayNotHasKey('COMPOSER', $spec->env);
    }

    #[Test]
    public function aBranchLockBehindItsHeadGetsTheWholePlainRemedy(): void
    {
        $this->project(['acme/one' => self::MAIN], ['acme/one' => self::MAIN]);
        $this->factory->processes->willSucceed(self::ONE_BEHIND);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertStringContainsString(
            self::HEADING
            . "  - acme/one (locked dev-main 93f1ae4, available dev-main 606a9f6)\n\n"
            . "Run `composer update`, then run QA again. There is no point checking code with\n"
            . "an out-of-date toolchain, or against out-of-date copies of your own packages.\n\n"
            . self::PIN_ADVICE,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aTagLockBehindANewerTagGetsTheWholeTagRemedy(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willSucceed("Updating dependencies\n  - Upgrading acme/one (1.0.0 => 1.1.0)\n");

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertStringContainsString(
            self::HEADING
            . "  - acme/one (locked 1.0.0, available 1.1.0)\n\n"
            . "For the packages that follow release tags, run `composer update acme/one --with-dependencies --prefer-stable`.\n"
            . "The newest tag within your constraint is what counts, and `--prefer-stable` keeps\n"
            . "Composer from choosing a branch your constraint also admits.\n"
            . "Then run QA again. There is no point checking code with an out-of-date toolchain,\n"
            . "or against out-of-date copies of your own packages.\n\n"
            . self::PIN_ADVICE,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aMixedBranchLockBehindItsHeadGetsTheWholeRequireRemedy(): void
    {
        $this->project(['acme/one' => self::MAIN_OR_RANGE], ['acme/one' => self::MAIN]);
        $this->factory->processes->willSucceed(self::ONE_BEHIND);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertStringContainsString(
            self::HEADING
            . "  - acme/one (locked dev-main 93f1ae4, available dev-main 606a9f6)\n\n"
            . "These packages are locked on a branch, but their constraint also admits release tags,\n"
            . "and Composer prefers a tag to a branch head, so `composer update` would move the lock\n"
            . "to a tag. To follow the branch, require it alone:\n"
            . "  composer require 'acme/one:dev-main' --with-dependencies\n"
            . "To follow release tags instead, require a tag range such as `^1.0` and run\n"
            . "`composer update <package> --with-dependencies --prefer-stable`.\n\n"
            . "Then run QA again. There is no point checking code with an out-of-date toolchain,\n"
            . "or against out-of-date copies of your own packages.\n\n"
            . self::PIN_ADVICE,
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function theDryRunIsAskedWithExactlyTheDocumentedOptions(): void
    {
        $this->project(['acme/one' => self::CARET, 'acme/two' => self::MAIN], ['acme/one' => self::TAG_LOCK, 'acme/two' => self::MAIN]);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER)->willSucceed(self::CURRENT_ANSWER);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        $common         = ['--with-dependencies', '--dry-run', '--no-interaction', '--no-ansi', '--no-scripts', '--no-progress', '--no-audit'];
        [$branch, $tag] = array_map(
            static fn (ProcessSpecDto $spec): array => \array_slice($spec->command, (int)array_search(self::UPDATE, $spec->command, true)),
            $this->factory->processes->specs,
        );
        self::assertSame([self::UPDATE, 'acme/two', ...$common], $branch);
        self::assertSame([self::UPDATE, 'acme/one', ...$common, self::PREFER_STABLE], $tag);
    }

    #[Test]
    public function anUnreadableLockIsReportedWithAnErrorLineAndTheIdentifier(): void
    {
        $this->factory->project->write(self::LOCK_FILE, self::NOT_JSON);

        $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertStringStartsWith('ERROR: composer.lock is not valid JSON: ', $printed);
        self::assertStringContainsString(DependencyFreshnessTool::IDENTIFIER, $printed);
    }

    /** A package name's case does not matter to composer.json lookups, branch alternatives or the --dev decision. */
    #[Test]
    public function packageNamesAreMatchedToComposerJsonWithoutRegardToCase(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, \Safe\json_encode([
            'require-dev' => ['acme/dev' => 'dev-main || ^1.0'],
        ]));
        $this->factory->project->write(self::LOCK_FILE, \Safe\json_encode([
            'packages'     => [],
            'packages-dev' => [['name' => 'Acme/Dev', 'version' => self::MAIN]],
        ]));
        $this->factory->processes->willSucceed("Updating dependencies\n  - Upgrading Acme/Dev (dev-main 93f1ae4 => dev-main 606a9f6)\n");

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns('*/*')->build()));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertContains('Acme/Dev', $this->factory->processes->lastSpec()->command);
        self::assertStringContainsString("  composer require --dev 'Acme/Dev:dev-main' --with-dependencies\n", $this->factory->output->fetch());
    }

    #[Test]
    public function aSkippedPlainBranchAskKeepsItsSkipWhenTheMixedAskIsFresh(): void
    {
        $this->project(['acme/one' => self::MAIN, 'acme/two' => self::MAIN_OR_RANGE], ['acme/one' => self::MAIN, 'acme/two' => self::MAIN]);
        $this->factory->processes->willFail(1, self::UNREACHABLE_ANSWER)->willSucceed(self::CURRENT_ANSWER);

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::NOT_VERIFIED_ONE, $this->factory->output->fetch());
    }

    #[Test]
    public function aSkippedAskDoesNotHideAStaleAnswerFromTheOther(): void
    {
        $this->project(['acme/one' => self::MAIN, 'acme/two' => self::CARET], ['acme/one' => self::MAIN, 'acme/two' => self::TAG_LOCK]);
        $this->factory->processes
            ->willSucceed(self::ONE_BEHIND)
            ->willFail(1, self::UNREACHABLE_ANSWER)
        ;

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('composer.lock was NOT verified for: acme/two.', $printed);
    }

    #[Test]
    public function aSkippedAskIsSkippedEvenWhenTheOtherIsFresh(): void
    {
        $this->project(['acme/one' => self::MAIN, 'acme/two' => self::CARET], ['acme/one' => self::MAIN, 'acme/two' => self::TAG_LOCK]);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER)->willFail(1, self::UNREACHABLE_ANSWER);

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('composer.lock was NOT verified for: acme/two.', $this->factory->output->fetch());
    }

    #[Test]
    public function aSkippedFirstAskKeepsItsSkipWhenTheSecondIsFresh(): void
    {
        $this->project(['acme/one' => self::MAIN, 'acme/two' => self::CARET], ['acme/one' => self::MAIN, 'acme/two' => self::TAG_LOCK]);
        $this->factory->processes->willFail(1, self::UNREACHABLE_ANSWER)->willSucceed(self::CURRENT_ANSWER);

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::NOT_VERIFIED_ONE, $this->factory->output->fetch());
    }

    #[Test]
    public function aPackageTheProjectDoesNotRequireIsJudgedByItsLockedVersion(): void
    {
        $this->project([], ['acme/one' => self::TAG_LOCK]);
        $this->factory->processes->willSucceed(self::CURRENT_ANSWER);

        $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertContains(self::PREFER_STABLE, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function anUnreadableComposerJsonFailsTheLane(): void
    {
        $this->lock(self::SELF);
        $this->factory->project->write(self::COMPOSER_JSON, self::NOT_JSON);

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('composer.json is not valid JSON', $result->summary);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aCooldownThatWithholdsTheLockedVersionItselfIsNotAFailureAndNotASkip(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willFail(2, self::cooldownRefusal(self::ACME_ONE));

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame("NOTE dependencyFreshness: acme/one is held back by the cooldown policy (config.policy.cooldown): no version its constraint allows is old enough yet, so it is not judged behind.\n", $printed);
        self::assertCount(1, $this->factory->processes->specs);
        self::assertStringNotContainsString('out of date', $printed);
        self::assertNotContains(self::NO_COOLDOWN, $this->factory->processes->lastSpec()->command);
    }

    #[Test]
    public function aPackageWithheldByTheCooldownDoesNotHideAnotherPackageThatIsBehind(): void
    {
        $this->project([self::ACME_ONE => self::CARET, self::ACME_TWO => self::CARET], [self::ACME_ONE => self::TAG_LOCK, self::ACME_TWO => self::TAG_LOCK]);
        $this->factory->processes
            ->willFail(2, self::cooldownRefusal(self::ACME_ONE))
            ->willFail(2, self::cooldownRefusal(self::ACME_ONE))
            ->willSucceed("Updating dependencies\n  - Upgrading acme/two (1.0.0 => 1.1.0)\n")
        ;

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        $asked = array_map(static fn (ProcessSpecDto $spec): array => array_values(array_filter(\array_slice($spec->command, (int)array_search(self::UPDATE, $spec->command, true) + 1), static fn (string $argument): bool => !str_starts_with($argument, '--'))), $this->factory->processes->specs);
        self::assertSame([[self::ACME_ONE, self::ACME_TWO], [self::ACME_ONE], [self::ACME_TWO]], $asked, 'the whole set once, then each package alone');

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 locked package is behind what composer update would install: acme/two', $result->summary);
        self::assertStringContainsString('  - acme/two (locked 1.0.0, available 1.1.0)', $printed);
        self::assertStringNotContainsString('  - acme/one', $printed);
        self::assertStringNotContainsString('SKIPPED', $printed);
    }

    #[Test]
    public function aBehindPackageIsFailedBesideAHeldBackOneAndNoCooldownBypassIsEverPassed(): void
    {
        $this->project([self::ACME_ONE => self::CARET, self::ACME_TWO => self::CARET], [self::ACME_ONE => self::TAG_LOCK, self::ACME_TWO => self::TAG_LOCK]);
        $this->factory->processes
            ->willFail(2, self::cooldownRefusal(self::ACME_TWO))
            ->willSucceed("Updating dependencies\n  - Upgrading acme/one (1.0.0 => 1.0.9)\n")
            ->willFail(2, self::cooldownRefusal(self::ACME_TWO))
        ;

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('  - acme/one (locked 1.0.0, available 1.0.9)', $printed);
        self::assertStringNotContainsString('SKIPPED', $printed);
        foreach ($this->factory->processes->specs as $spec) {
            self::assertNotContains(self::NO_COOLDOWN, $spec->command, 'the policy is followed, not bypassed');
        }
    }

    #[Test]
    public function aCooldownRefusalBesideARealConflictIsSkippedNotHeldBack(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willFail(2, "Your requirements could not be resolved to an installable set of packages.\n\n  Problem 1\n    - Root composer.json requires acme/one ^1.0 but it conflicts with your root composer.json require (^2.0).\n\n" . self::cooldownProblem(2, self::ACME_ONE));

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE, $printed);
        self::assertStringNotContainsString(self::HELD_BACK, $printed);
    }

    #[Test]
    public function aCooldownRefusalOfADependencyOfTheAskedPackageIsSkippedNotHeldBack(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willFail(2, self::cooldownRefusal('acme/dependency'));

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringNotContainsString(self::HELD_BACK, $printed);
        self::assertCount(1, $this->factory->processes->specs);
    }

    #[Test]
    public function aRunThatDidNotExitWithComposersResolutionFailureCodeIsSkippedNotHeldBack(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willFail(1, self::cooldownRefusal(self::ACME_ONE));

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringNotContainsString(self::HELD_BACK, $this->factory->output->fetch());
    }

    #[Test]
    public function aRunKilledBySignalAfterPrintingTheRefusalIsSkippedNotHeldBack(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willReturn(new ProcessResultDto(2, self::cooldownRefusal(self::ACME_ONE), '', 15));

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringNotContainsString(self::HELD_BACK, $this->factory->output->fetch());
    }

    #[Test]
    public function aStalePackageIsFailedAndARealFailureOfAnotherIsStillNamedAsNotVerified(): void
    {
        $this->project([self::ACME_ONE => self::CARET, self::ACME_TWO => self::CARET], [self::ACME_ONE => self::TAG_LOCK, self::ACME_TWO => self::TAG_LOCK]);
        $this->factory->processes
            ->willFail(2, self::cooldownRefusal(self::ACME_ONE))
            ->willFail(1, self::UNREACHABLE_ANSWER)
            ->willSucceed("Updating dependencies\n  - Upgrading acme/two (1.0.0 => 1.1.0)\n")
        ;

        $result  = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('  - acme/two (locked 1.0.0, available 1.1.0)', $printed);
        self::assertStringContainsString(self::NOT_VERIFIED_ONE, $printed);
    }

    #[Test]
    public function aFailureThatIsNotTheCooldownIsStillSkippedByName(): void
    {
        $this->project([self::ACME_ONE => self::CARET], [self::ACME_ONE => self::TAG_LOCK]);
        $this->factory->processes->willFail(2, "Your requirements could not be resolved to an installable set of packages.\n\n  Problem 1\n    - Root composer.json requires acme/one ^1.0 but it conflicts with your root composer.json require (^2.0).\n");

        $result = $this->tool()->run($this->factory->context($this->factory->builder()->withDependencyFreshnessPatterns(self::ACME_GLOB)->build()));

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertCount(1, $this->factory->processes->specs);
    }

    /**
     * A project with a composer.json requiring $constraints and a lock holding $versions
     * (matching package names by the freshness patterns configured per test).
     *
     * @param array<string, string> $constraints package => composer.json constraint
     * @param array<string, string> $versions    package => locked version
     */
    private function project(array $constraints, array $versions): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, \Safe\json_encode(['name' => 'fixture/ctx', 'type' => 'project', 'require' => (object)$constraints]));
        $this->factory->project->write(self::LOCK_FILE, \Safe\json_encode([
            'packages'     => array_map(static fn (string $name, string $version): array => ['name' => $name, 'version' => $version], array_keys($versions), array_values($versions)),
            'packages-dev' => [],
        ]));
    }

    /**
     * What Composer 2.11 printed, exit 2, with config.policy.cooldown set and
     * every version of each package inside the period (the locked one too):
     * one Problem per package.
     */
    private static function cooldownRefusal(string ...$packages): string
    {
        $problems = [];
        foreach (array_values($packages) as $index => $package) {
            $problems[] = self::cooldownProblem($index + 1, $package);
        }

        return "Loading composer repositories with package information\n20 package version(s) withheld by the cooldown policy (run with -vv to list them).\nUpdating dependencies\nYour requirements could not be resolved to an installable set of packages.\n\n" . implode("\n", $problems);
    }

    private static function cooldownProblem(int $number, string $package): string
    {
        return \sprintf("  Problem %d\n    - Root composer.json requires %s ^1.0, found %s[1.0.0, 1.0.1] but these were not loaded, because they are still in the cooldown period configured in \"policy.cooldown\" (available in 2 days 22 hours). To exempt the package from the cooldown policy, add it to the \"policy.cooldown.ignore\" config, or run the command with --no-cooldown for a one-off bypass.\n", $number, $package, $package);
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

    /** @return list<string> the files in the project root, sorted */
    private function projectFiles(): array
    {
        return $this->factory->project->files();
    }

    /** @return list<string> the package names the dry run was asked about */
    private function packagesAskedAbout(): array
    {
        $command   = $this->factory->processes->lastSpec()->command;
        $arguments = \array_slice($command, (int)array_search(self::UPDATE, $command, true) + 1);

        return array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));
    }
}
