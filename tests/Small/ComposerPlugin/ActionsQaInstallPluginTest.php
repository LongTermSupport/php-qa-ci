<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event;
use Composer\Util\ProcessExecutor;
use LTS\PHPQA\ActionsQa\ActionsQaInstaller;
use LTS\PHPQA\ComposerPlugin\ActionsQaInstallPlugin;
use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\CapturingIO;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\FakeRootPackage;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Pins the plugin's Composer event-subscription wiring. Composer never runs a dependency's own
 * scripts, so this subscription is the only way a consuming project's install reaches
 * bin/actions-qa-install. See PhpStanGuardPluginTest for why the map is asserted against literal
 * Composer event-name strings.
 *
 * @internal
 */
#[CoversClass(ActionsQaInstallPlugin::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class ActionsQaInstallPluginTest extends TestCase
{
    private const string COMPOSER_JSON = __DIR__ . '/../../../composer.json';

    /** The installer's state check, when the killed install left no bundle. */
    private const string NOT_INSTALLED = 'actions-qa-ci is not installed in vendor-bin/actions-qa-ci, so the actionsQa lane crashes until it is installed.';

    /** The vendor directory of the install under test. */
    private string $vendor = '';

    private ?TempDir $vendorDir = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../assets/ComposerPlugin/composer-stubs.php';
    }

    protected function setUp(): void
    {
        ProcessExecutor::$commands = [];
    }

    protected function tearDown(): void
    {
        ProcessExecutor::$exitCode = 1;
        ProcessExecutor::$queue    = [];
        ProcessExecutor::$commands = [];
        $this->vendorDir?->remove();
    }

    #[Test]
    public function bothInstallAndUpdateInstallActionsQa(): void
    {
        self::assertSame(
            [
                'post-install-cmd' => 'installActionsQa',
                'post-update-cmd'  => 'installActionsQa',
            ],
            ActionsQaInstallPlugin::getSubscribedEvents(),
        );
    }

    /** The installer is a separate process, so the plugin repeats its exit status; the two stay equal. */
    #[Test]
    public function theStateCheckExitStatusThePluginRelaysIsTheInstallers(): void
    {
        self::assertSame(
            ActionsQaInstaller::EXIT_NOT_CURRENT,
            new ReflectionClassConstant(ActionsQaInstallPlugin::class, 'STATE_NOT_CURRENT')->getValue(),
        );
    }

    #[Test]
    public function theInstallRunsTheInstalledPackagesInstallerInInstallMode(): void
    {
        $this->installExitingWith(0);

        self::assertSame(
            [escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($this->vendor . '/lts/php-qa-ci/bin/actions-qa-install') . ' install'],
            ProcessExecutor::$commands,
        );
    }

    #[Test]
    public function aSuccessfulInstallReportsNothing(): void
    {
        self::assertSame([], $this->installExitingWith(0));
        self::assertCount(1, ProcessExecutor::$commands);
    }

    /** A failed install never fails the project's install; the installer's own lines say what happened. */
    #[Test]
    public function aFailedInstallIsReportedAndNotThrown(): void
    {
        self::assertSame(
            ["<comment>php-qa-ci: the actions-qa-ci install failed with exit code 1; the installer's output above says what happened</comment>"],
            $this->installExitingWith(1),
        );
        self::assertCount(1, ProcessExecutor::$commands);
    }

    /** A killed installer says nothing, so the plugin asks the read-only state check and relays it. */
    #[Test]
    public function anInstallerKilledByASignalIsFollowedByWhatItLeftOnDisk(): void
    {
        $err = $this->installExitingWith(137, [ActionsQaInstaller::EXIT_NOT_CURRENT, self::NOT_INSTALLED . "\n"]);

        self::assertSame(
            [
                '<comment>php-qa-ci: the actions-qa-ci install failed with exit code 137, killed by signal 9 (SIGKILL, which is what the out-of-memory killer sends)</comment>',
                '<comment>php-qa-ci: what the killed install left on disk: ' . self::NOT_INSTALLED . '</comment>',
            ],
            $err,
        );
        self::assertSame($this->stateCommand(), ProcessExecutor::$commands[1] ?? null, "the state check is the installer's own, in state mode, with its errors on stderr");
    }

    /** A state check that fails too leaves the state unknown, and the line says how to look. */
    #[Test]
    public function aStateCheckThatFailsSaysWhatTheInstallLeftIsUnknownAndHowToCheck(): void
    {
        $err = $this->installExitingWith(143, [255, '']);

        self::assertSame(
            '<comment>php-qa-ci: what the killed install left on disk is unknown, because checking it failed with exit code 255. Run ' . $this->stateCommand() . ' to see whether the actionsQa lane can run</comment>',
            $err[1] ?? null,
        );
    }

    /** A check that ends cleanly yet prints nothing has said nothing about the disk either. */
    #[Test]
    public function aStateCheckThatPrintsNothingLeavesTheStateUnknown(): void
    {
        self::assertStringStartsWith(
            '<comment>php-qa-ci: what the killed install left on disk is unknown, because checking it printed nothing. Run ',
            $this->installExitingWith(143, [0, "\n"])[1] ?? '',
        );
    }

    /** php-qa-ci as the root package installs the bundle through scripts/tool-install.bash. */
    #[Test]
    public function phpQaCiAsTheRootPackageRunsNothing(): void
    {
        self::assertSame([], $this->installExitingWith(1, null, 'lts/php-qa-ci'));
        self::assertSame([], ProcessExecutor::$commands);
    }

    /** Composer loads only the plugin classes composer.json lists. */
    #[Test]
    public function composerJsonRegistersThePlugin(): void
    {
        $composer = \Safe\json_decode(\Safe\file_get_contents(self::COMPOSER_JSON), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['extra'] ?? null);

        self::assertContains(ActionsQaInstallPlugin::class, $composer['extra']['class'] ?? []);
    }

    /** The command the plugin runs to ask what is on disk. */
    private function stateCommand(): string
    {
        return escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($this->vendor . '/lts/php-qa-ci/bin/actions-qa-install') . ' state';
    }

    /**
     * @param array{int, string}|null $stateCheck how the state check ends, when the plugin runs one
     *
     * @return list<string> what the plugin wrote to stderr
     */
    private function installExitingWith(int $exitCode, ?array $stateCheck = null, string $rootPackage = 'acme/consumer'): array
    {
        ProcessExecutor::$queue    = null === $stateCheck ? [[$exitCode, '']] : [[$exitCode, ''], $stateCheck];
        ProcessExecutor::$exitCode = 1;
        $this->vendorDir           = TempDir::create('actions-qa-plugin-vendor');
        $this->vendor              = $this->vendorDir->path;

        $this->vendorDir->write('lts/php-qa-ci/bin/actions-qa-install', '<?php');
        $io = new CapturingIO();
        new ActionsQaInstallPlugin()->installActionsQa(new Event(new Composer(new Config(['vendor-dir' => $this->vendor]), new FakeRootPackage($rootPackage)), $io));

        return $io->err;
    }
}
