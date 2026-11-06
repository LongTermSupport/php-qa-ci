<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use LTS\PHPQA\Pipeline\Config\DependencyFreshnessPatterns;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\ComposerLock;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\OutdatedPackageDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyConstraintFreshness;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyConstraintJudge;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyVendors;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyVerdictEnum;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\OutdatedOutput;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Tracking;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\UpdateDryRunOutput;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshnessTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * First-party constraint freshness: a project depends on a package of its own
 * vendor with a loose constraint (`^0.2`), a newer release (0.3.0) exists that
 * the constraint excludes, so `composer update` can never reach it. The lane
 * fails, unless the constraint is an exact pin. Composer's answer is the
 * shape `composer outdated --locked --direct --format=json` printed.
 *
 * @internal
 */
#[CoversClass(DependencyFreshnessTool::class)]
#[CoversClass(FirstPartyConstraintFreshness::class)]
#[CoversClass(FirstPartyVendors::class)]
#[CoversClass(FirstPartyConstraintJudge::class)]
#[CoversClass(FirstPartyVerdictEnum::class)]
#[CoversClass(OutdatedOutput::class)]
#[CoversClass(OutdatedPackageDto::class)]
#[CoversClass(Tracking::class)]
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
final class FirstPartyConstraintFreshnessTest extends TestCase
{
    private const string COMPOSER = '/opt/fixture/bin/composer';

    private const string WIDGETS = 'acme/widgets';

    private const string GADGETS = 'acme/gadgets';

    private const string CARET_TWO = '^0.2';

    private const string CARET_ONE = '^1.0';

    private const string LOCKED = '0.2.1';

    private const string NEWEST = '0.3.0';

    private const string ONE = '1.0.0';

    private const string PSR_LOG = 'psr/log';

    private const string COMPOSER_JSON = 'composer.json';

    private const string SELF_BRANCH = 'dev-php8.5';

    private const string OWN_NAME = 'acme/app';

    private const string SELF = 'lts/php-qa-ci';

    private const string NOTE = 'NOTE dependencyFreshness: ';

    private const string SKIP_LINE = 'SKIPPED dependencyFreshness (phpqaci.dependencyFreshness): could not determine whether first-party dependency constraints are current: ';

    private const string NOT_VERIFIED = 'first-party constraints were NOT verified for: acme/widgets.';

    private const string IDENTIFIER_LINE = "\n🪪  phpqaci.dependencyFreshness  (vendor/bin/rule-doc phpqaci.dependencyFreshness)\n";

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
    public function aLooseConstraintThatExcludesANewerReleaseFailsNamingTheRemedy(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willSucceed($this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST]));

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 first-party constraint excludes a newer release: acme/widgets', $result->summary);
        self::assertStringContainsString("First-party dependency constraints exclude newer releases:\n\n", $printed);
        self::assertStringContainsString('  - acme/widgets: constraint "^0.2" excludes the newest release 0.3.0 (locked 0.2.1)', $printed);
        self::assertStringContainsString('widen it to "^0.3", or to "^0.2 || ^0.3"', $printed);
        self::assertStringContainsString('pin it exactly: "0.2.1"', $printed);
        self::assertStringContainsString('`composer update` can never move to a release the constraint excludes', $printed);
        self::assertStringContainsString($this->vendorsLine('acme', "the vendor of this project's own name"), $printed);
        self::assertStringContainsString(self::IDENTIFIER_LINE, $printed);
    }

    #[Test]
    public function theCheckAsksComposerWhichLockedDirectDependenciesAreOutdated(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willSucceed($this->outdated());

        $this->tool()->run($this->factory->context());

        $spec = $this->factory->processes->lastSpec();
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-d', 'display_errors=stderr', '-f', self::COMPOSER, '--', 'outdated', '--locked', '--direct', '--format=json', '--no-interaction', '--no-ansi', '--no-scripts'],
            $spec->command,
        );
        self::assertSame($this->factory->project->path, $spec->cwd);
        self::assertFalse($spec->streamOutput, 'a project that is current is checked in silence');
        self::assertSame((float)DependencyFreshnessTool::TIMEOUT_SECONDS, $spec->timeout);
        self::assertSame('0', $spec->env['GIT_TERMINAL_PROMPT'] ?? null);
        self::assertSame('1', $spec->env['COMPOSER_NO_INTERACTION'] ?? null);
    }

    #[Test]
    public function everyFirstPartyDependencyIsNamedInOneFailure(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO, self::GADGETS => '~1.2.0'], [self::WIDGETS => self::LOCKED, self::GADGETS => '1.2.3'], [self::GADGETS]);
        $this->factory->processes->willSucceed($this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST], [self::GADGETS, '1.2.3', '2.0.0'], [self::PSR_LOG, self::ONE, '3.0.0']));

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('2 first-party constraints exclude a newer release: acme/widgets, acme/gadgets', $result->summary);
        self::assertStringContainsString('  - acme/gadgets: constraint "~1.2.0" excludes the newest release 2.0.0 (locked 1.2.3)', $printed);
        self::assertStringContainsString('widen it to "^2.0", or to "~1.2.0 || ^2.0"', $printed);
        self::assertStringContainsString('composer update acme/widgets acme/gadgets', $printed);
        self::assertStringNotContainsString(self::PSR_LOG, $printed, 'another vendor is not first party');
    }

    #[Test]
    public function anExactPinIsReportedButDoesNotFail(): void
    {
        $this->project([self::WIDGETS => '=0.2.3', self::GADGETS => self::ONE], [self::WIDGETS => '0.2.3', self::GADGETS => self::ONE]);
        $this->factory->processes->willSucceed($this->outdated([self::WIDGETS, '0.2.3', self::NEWEST]));

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::NOTE . 'acme/widgets is pinned exactly to "=0.2.3" and a newer release exists (0.3.0): not failed, because an exact pin is deliberate.', $printed);
        self::assertStringNotContainsString('acme/gadgets', $printed, 'a pin with nothing newer is not worth a line');
        self::assertStringNotContainsString('phpqaci.dependencyFreshness  (vendor/bin/rule-doc', $printed);
    }

    #[Test]
    public function aBranchConstraintIsLeftToTheLockCheckInSilence(): void
    {
        $this->project([self::WIDGETS => 'dev-main'], [self::WIDGETS => 'dev-main']);
        $this->factory->processes->willSucceed($this->outdated([self::WIDGETS, 'dev-main abc1234', self::NEWEST]));

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function aConstraintThatAdmitsTheNewestReleaseOrAnotherVendorPassesInSilence(): void
    {
        $this->project([self::WIDGETS => '^0.2 || ^0.3', self::PSR_LOG => self::CARET_ONE], [self::WIDGETS => self::LOCKED, self::PSR_LOG => self::ONE]);
        $this->factory->processes->willSucceed($this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST], [self::PSR_LOG, self::ONE, '3.0.0']));

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function aProjectWithNothingOutdatedPassesInSilence(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willSucceed($this->outdated());

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
    }

    #[Test]
    public function theExtraKeyNamesTheFirstPartyVendorsInsteadOfTheOwnOne(): void
    {
        $this->project(['widgetco/lib' => self::CARET_TWO, self::WIDGETS => self::CARET_TWO], ['widgetco/lib' => self::LOCKED, self::WIDGETS => self::LOCKED], extra: ['php-qa-ci' => ['firstPartyVendors' => ['widgetco']]]);
        $this->factory->processes->willSucceed($this->outdated(['widgetco/lib', self::LOCKED, self::NEWEST], [self::WIDGETS, self::LOCKED, self::NEWEST]));

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 first-party constraint excludes a newer release: widgetco/lib', $result->summary);
        self::assertStringContainsString($this->vendorsLine('widgetco', 'extra.php-qa-ci.firstPartyVendors'), $printed);
        self::assertStringNotContainsString('acme/widgets', $printed);
    }

    #[Test]
    public function aProjectWithNoNameAndNoKeySaysItHasNoFirstPartyVendorsAndAsksComposerNothing(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, \Safe\json_encode(['require' => [self::WIDGETS => self::CARET_TWO]]));
        $this->writeLock([self::WIDGETS => self::LOCKED]);

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertStringContainsString(self::NOTE . 'no first-party vendors: composer.json has no "name" and no extra.php-qa-ci.firstPartyVendors, so first-party constraints are not checked.', $printed);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aProjectWithNoFirstPartyDirectDependencyPassesQuietlyWithoutAskingComposer(): void
    {
        $this->project([self::PSR_LOG => self::CARET_ONE], [self::PSR_LOG => self::ONE]);

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aFirstPartyPackageThatIsOnlyTransitiveIsNotJudged(): void
    {
        $this->project([self::PSR_LOG => self::CARET_ONE], [self::PSR_LOG => self::ONE, self::WIDGETS => self::LOCKED]);

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aMalformedExtraKeyFailsByName(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED], extra: ['php-qa-ci' => ['firstPartyVendors' => 'acme']]);

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('ERROR: extra.php-qa-ci.firstPartyVendors must be a list of vendor names', $printed);
        self::assertStringContainsString(self::IDENTIFIER_LINE, $printed);
    }

    #[Test]
    public function theSwitchTurnsTheCheckOffWithoutAskingComposer(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);

        $config = $this->factory->builder()->withFirstPartyConstraintFreshness(false)->build();
        $result = $this->tool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame('', $this->factory->output->fetch());
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function composerFailingIsSkippedByNameNeverFailed(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willFail(1, "Loading composer repositories with package information\n\nIn Git.php line 1:\n\n  Could not resolve host\n\n");

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE . 'composer failed (exit 1): Could not resolve host. ' . self::NOT_VERIFIED, $printed);
        self::assertStringNotContainsString('rule-doc', $printed, 'a skip is not a failure');
    }

    #[Test]
    public function composerTimingOutIsSkippedByName(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willRun(static fn (ProcessSpecDto $spec): ProcessResultDto => throw new ProcessTimedOutException(new Process($spec->command, timeout: $spec->timeout), ProcessTimedOutException::TYPE_GENERAL));

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE . 'composer did not answer within 180 seconds. ' . self::NOT_VERIFIED, $this->factory->output->fetch());
    }

    #[Test]
    public function aComposerThatPrintedNoJsonIsSkippedByName(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willSucceed("exec /usr/bin/composer \"\$@\"\n");

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE . 'composer gave no recognisable answer (it printed: exec /usr/bin/composer "$@"). ' . self::NOT_VERIFIED, $printed);
    }

    #[Test]
    public function anAnswerFromAStaleCacheIsSkippedByName(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willReturn(new ProcessResultDto(
            0,
            "https://repo.example could not be fully loaded (curl error 6), package information was loaded from the local cache and may be out of date\n" . $this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST]),
            $this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST]),
        ));

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString('could not be fully loaded', $this->factory->output->fetch());
    }

    #[Test]
    public function noLockIsSkippedByNameWhenThereIsAFirstPartyDependency(): void
    {
        $this->factory->project->write(self::COMPOSER_JSON, \Safe\json_encode(['name' => 'acme/app', 'require' => [self::WIDGETS => self::CARET_TWO]]));

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE . 'there is no composer.lock. ' . self::NOT_VERIFIED, $this->factory->output->fetch());
        self::assertSame([], $this->factory->processes->specs);
    }

    #[Test]
    public function aStaleLockAndAnExcludedReleaseAreBothReportedInOneFailure(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED, self::SELF => self::SELF_BRANCH]);
        $this->factory->processes
            ->willSucceed("Updating dependencies\nLock file operations: 0 installs, 1 update, 0 removals\n  - Upgrading lts/php-qa-ci (dev-php8.5 93f1ae4 => dev-php8.5 606a9f6)\n")
            ->willSucceed($this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST]))
        ;

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('1 locked package is behind what composer update would install: lts/php-qa-ci; 1 first-party constraint excludes a newer release: acme/widgets', $result->summary);
        self::assertStringContainsString('lts/php-qa-ci (locked dev-php8.5 93f1ae4', $printed);
        self::assertStringContainsString('acme/widgets: constraint "^0.2"', $printed);
    }

    #[Test]
    public function aSkippedLockCheckDoesNotHideAnExcludedRelease(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED, self::SELF => self::SELF_BRANCH]);
        $this->factory->processes
            ->willFail(1, "In VcsRepository.php line 193:\n\n  No driver found\n")
            ->willSucceed($this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST]))
        ;

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('SKIPPED dependencyFreshness', $printed);
        self::assertStringContainsString('acme/widgets: constraint "^0.2"', $printed);
    }

    #[Test]
    public function aConstraintComposerCannotParseIsSkippedByNameNotFailed(): void
    {
        $this->project([self::WIDGETS => '^^'], [self::WIDGETS => self::LOCKED]);
        $this->factory->processes->willSucceed($this->outdated([self::WIDGETS, self::LOCKED, self::NEWEST]));

        $result  = $this->tool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
        self::assertStringContainsString(self::SKIP_LINE . 'a constraint is not one the version parser reads: ', $printed);
        self::assertStringContainsString(self::NOT_VERIFIED, $printed);
    }

    #[Test]
    public function aSkippedFirstPartyCheckWithAPassingLockCheckIsSkipped(): void
    {
        $this->project([self::WIDGETS => self::CARET_TWO], [self::WIDGETS => self::LOCKED, self::SELF => self::SELF_BRANCH]);
        $this->factory->processes
            ->willSucceed("Updating dependencies\nNothing to modify in lock file\n")
            ->willFail(1, "In Git.php line 1:\n\n  offline\n")
        ;

        $result = $this->tool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Skipped, $result->outcome);
    }

    /**
     * @param array<string, string>        $require     package => constraint
     * @param array<string, string>        $locked      package => locked version
     * @param list<string>                 $devPackages packages to move from `require` to `require-dev`
     * @param array<array-key, mixed>|null $extra
     */
    private function project(array $require, array $locked, array $devPackages = [], ?array $extra = null): void
    {
        $prod = array_diff_key($require, array_flip($devPackages));
        $dev  = array_intersect_key($require, array_flip($devPackages));
        $json = ['name' => self::OWN_NAME, 'type' => 'project', 'require' => (object)$prod, 'require-dev' => (object)$dev];
        if (null !== $extra) {
            $json['extra'] = $extra;
        }

        $this->factory->project->write(self::COMPOSER_JSON, \Safe\json_encode($json));
        $this->writeLock($locked);
    }

    /** @param array<string, string> $locked package => locked version */
    private function writeLock(array $locked): void
    {
        $this->factory->project->write('composer.lock', \Safe\json_encode([
            'packages'     => array_map(static fn (string $name, string $version): array => ['name' => $name, 'version' => $version], array_keys($locked), array_values($locked)),
            'packages-dev' => [],
        ]));
    }

    /**
     * What `composer outdated --locked --direct --format=json` prints.
     *
     * @param array{string, string, string} ...$packages name, locked version, newest version
     */
    private function outdated(array ...$packages): string
    {
        return \Safe\json_encode(['locked' => array_map(
            static fn (array $package): array => ['name' => $package[0], 'direct-dependency' => true, 'version' => $package[1], 'latest' => $package[2], 'latest-status' => 'update-possible'],
            array_values($packages),
        )], JSON_PRETTY_PRINT);
    }

    private function vendorsLine(string $vendors, string $source): string
    {
        return \sprintf('First-party vendors: %s (from %s).', $vendors, $source);
    }

    private function tool(): DependencyFreshnessTool
    {
        return new DependencyFreshnessTool(self::COMPOSER);
    }
}
