<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event;
use Composer\Util\ProcessExecutor;
use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\ComposerPlugin\TurboInstallPlugin;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\CapturingIO;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\FakeRootPackage;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Pins the plugin's Composer event-subscription wiring. Composer never runs a dependency's own
 * scripts, so this subscription is the only way a consuming project's install reaches
 * bin/turbo-install. See PhpStanGuardPluginTest for why the map is asserted against literal
 * Composer event-name strings.
 *
 * @internal
 */
#[CoversClass(TurboInstallPlugin::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class TurboInstallPluginTest extends TestCase
{
    private const string COMPOSER_JSON = __DIR__ . '/../../../composer.json';

    /** The installer's state check, when a failed install left nothing PHPStan loads. */
    private const string NOTHING_LOADS = 'No Turbo binary is in place beside its core, so PHPStan runs without Turbo until an install succeeds.';

    /** The installer's state check, when a binary is still beside its core. */
    private const string MAY_STILL_LOAD = 'WARNING: phpstan.phar may still load vendor-phar/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so, which is still beside its core, though this install could not vouch for it. Delete it to run PHPStan without Turbo.';

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
    public function bothInstallAndUpdateInstallTurbo(): void
    {
        self::assertSame(
            [
                'post-install-cmd' => 'installTurbo',
                'post-update-cmd'  => 'installTurbo',
            ],
            TurboInstallPlugin::getSubscribedEvents(),
        );
    }

    /**
     * Some failures leave a binary phpstan.phar still loads, so the plugin cannot know whether
     * PHPStan runs without Turbo; only the installer's own lines can say what it left in place.
     */
    #[Test]
    public function aFailedInstallIsReportedWithoutClaimingWhatPhpstanLoads(): void
    {
        self::assertSame(
            ["<comment>php-qa-ci: the PHPStan Turbo install failed with exit code 1; the installer's output above says what happened</comment>"],
            $this->installExitingWith(1),
        );
    }

    /**
     * A killed installer prints nothing about its death, so the line names the signal and points
     * at no output; a segfault is named as one, to be reported to php-qa-ci, whose installer it
     * is, and the install still goes on.
     */
    #[Test]
    public function anInstallerKilledByASignalIsReportedByItsSignal(): void
    {
        self::assertSame(
            '<comment>php-qa-ci: the PHPStan Turbo install failed with exit code 139, killed by signal 11 (SIGSEGV): it crashed with a segmentation fault, a defect to report at https://github.com/LongTermSupport/php-qa-ci/issues and never to retry past</comment>',
            $this->installExitingWith(139, [0, self::NOTHING_LOADS])[0],
        );
    }

    /**
     * A killed installer never reached the line saying what it left on disk, and it may have left
     * a binary beside its core that nothing vouches for; the plugin asks the installer's read-only
     * state check and relays its answer.
     */
    #[Test]
    public function anInstallerKilledByASignalIsFollowedByWhatItLeftOnDisk(): void
    {
        $err = $this->installExitingWith(137, [0, self::MAY_STILL_LOAD . "\n"]);

        self::assertSame(
            [
                '<comment>php-qa-ci: the PHPStan Turbo install failed with exit code 137, killed by signal 9 (SIGKILL, which is what the out-of-memory killer sends)</comment>',
                '<comment>php-qa-ci: what the killed install left on disk: ' . self::MAY_STILL_LOAD . '</comment>',
            ],
            $err,
        );
        self::assertSame($this->stateCommand(), ProcessExecutor::$commands[1] ?? null, "the state check is the installer's own, in state mode");
    }

    /**
     * Composer captures only the check's stdout, and PHP CLI with display_errors on writes a warning
     * there too; the check runs with its errors sent to stderr, so the relayed line is only what
     * the check printed. The check really runs here, under an ini that would put the warning on
     * stdout.
     */
    #[Test]
    public function theStateCheckRunsWithItsErrorsKeptOutOfTheLineThePluginRelays(): void
    {
        $this->installExitingWith(137, [0, self::NOTHING_LOADS . "\n"], "<?php\ntrigger_error('a warning the check did not mean to print', E_USER_WARNING);\necho 'the line the check prints', \"\\n\";\n");

        // A host whose PHP CLI shows errors on stdout and logs none, as php.ini-development does.
        $ini = TempDir::create('turbo-plugin-ini');

        try {
            $ini->write('zz-display-errors-on-stdout.ini', "display_errors=1\nlog_errors=0\nerror_reporting=-1\n");
            $check = Process::fromShellCommandline(ProcessExecutor::$commands[1] ?? 'false', null, ['PHP_INI_SCAN_DIR' => \PATH_SEPARATOR . $ini->path]);
            $check->run();
        } finally {
            $ini->remove();
        }

        self::assertSame("the line the check prints\n", $check->getOutput());
        self::assertStringContainsString('a warning the check did not mean to print', $check->getErrorOutput());
    }

    /** A state check that fails too leaves the state unknown, and the line says how to look. */
    #[Test]
    public function aStateCheckThatFailsSaysWhatTheInstallLeftIsUnknownAndHowToCheck(): void
    {
        $err = $this->installExitingWith(143, [255, '']);

        self::assertSame(
            '<comment>php-qa-ci: what the killed install left on disk is unknown, because checking it failed with exit code 255. Run ' . $this->stateCommand() . ' to see whether phpstan.phar will load a Turbo binary that nothing vouches for</comment>',
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

    /** An installer that failed on its own says what it left itself, so nothing more is run. */
    #[Test]
    public function anInstallThatFailedWithoutASignalRunsNoStateCheck(): void
    {
        $this->installExitingWith(1);

        self::assertCount(1, ProcessExecutor::$commands);
    }

    #[Test]
    public function aSuccessfulInstallReportsNothing(): void
    {
        self::assertSame([], $this->installExitingWith(0));
        self::assertCount(1, ProcessExecutor::$commands);
    }

    /** Composer loads only the plugin classes composer.json lists. */
    #[Test]
    public function composerJsonRegistersThePlugin(): void
    {
        $composer = \Safe\json_decode(\Safe\file_get_contents(self::COMPOSER_JSON), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['extra'] ?? null);

        self::assertContains(TurboInstallPlugin::class, $composer['extra']['class'] ?? []);
    }

    /** The command the plugin runs to ask what is on disk. */
    private function stateCommand(): string
    {
        return escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($this->vendor . '/lts/php-qa-ci/bin/turbo-install') . ' state';
    }

    /**
     * @param array{int, string}|null $stateCheck how the state check ends, when the plugin runs one
     * @param string                  $installer  the installer the vendor directory holds, which the
     *                                            stubbed executor never runs
     *
     * @return list<string> what the plugin wrote to stderr
     */
    private function installExitingWith(int $exitCode, ?array $stateCheck = null, string $installer = '<?php'): array
    {
        ProcessExecutor::$queue    = null === $stateCheck ? [[$exitCode, '']] : [[$exitCode, ''], $stateCheck];
        ProcessExecutor::$exitCode = 1;
        $this->vendorDir           = TempDir::create('turbo-plugin-vendor');
        $this->vendor              = $this->vendorDir->path;

        $this->vendorDir->write('lts/php-qa-ci/bin/turbo-install', $installer);
        $io = new CapturingIO();
        new TurboInstallPlugin()->installTurbo(new Event(new Composer(new Config(['vendor-dir' => $this->vendor]), new FakeRootPackage()), $io));

        return $io->err;
    }
}
