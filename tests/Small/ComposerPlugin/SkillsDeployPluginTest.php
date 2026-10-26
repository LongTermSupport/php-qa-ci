<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event;
use Composer\Util\ProcessExecutor;
use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\ComposerPlugin\SkillsDeployPlugin;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\CapturingIO;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\FakeRootPackage;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the plugin's Composer event-subscription wiring. See PhpStanGuardPluginTest
 * for why the map is asserted against literal Composer event-name strings rather
 * than referencing ScriptEvents (composer/composer is not installed; the plugin
 * tree is PHPStan-excluded).
 *
 * @internal
 */
#[CoversClass(SkillsDeployPlugin::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class SkillsDeployPluginTest extends TestCase
{
    private const string DISABLE = 'PHP_QA_CI_DISABLE_CONFIG_PUSH';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../assets/ComposerPlugin/composer-stubs.php';
    }

    #[Test]
    public function bothInstallAndUpdateDeploySkills(): void
    {
        self::assertSame(
            [
                'post-install-cmd' => ['deploySkills', -10],
                'post-update-cmd'  => ['deploySkills', -10],
            ],
            SkillsDeployPlugin::getSubscribedEvents(),
        );
    }

    /**
     * The deploy reads the rule includes phpstan/extension-installer writes in its own
     * post-install and post-update handler at Composer's default priority 0. Composer runs a
     * higher priority first and equal priorities in registration order, so only a priority
     * below 0 guarantees the installer has written them.
     */
    #[Test]
    public function theDeployRunsAfterTheExtensionInstallerAtPriorityZero(): void
    {
        foreach (SkillsDeployPlugin::getSubscribedEvents() as $listener) {
            self::assertIsArray($listener);
            self::assertLessThan(0, $listener[1] ?? 0);
        }
    }

    #[Test]
    public function aFailedDeployNamesItsExitCode(): void
    {
        self::assertContains('<error>✗ Skills/Agents/Hooks deployment failed with exit code 1</error>', $this->deployExitingWith(1));
    }

    /** A killed deploy script prints nothing about its death; the line names the signal. */
    #[Test]
    public function aDeployKilledByASignalNamesTheSignal(): void
    {
        self::assertContains('<error>✗ Skills/Agents/Hooks deployment failed with exit code 143, killed by signal 15 (SIGTERM)</error>', $this->deployExitingWith(143));
    }

    /** The deploy script is php-qa-ci's own, so its crash is a defect for php-qa-ci's tracker. */
    #[Test]
    public function aSegfaultingDeployNamesPhpQaCisTracker(): void
    {
        self::assertContains('<error>✗ Skills/Agents/Hooks deployment failed with exit code 139, killed by signal 11 (SIGSEGV): it crashed with a segmentation fault, a defect to report at https://github.com/LongTermSupport/php-qa-ci/issues and never to retry past</error>', $this->deployExitingWith(139));
    }

    #[Test]
    public function aSuccessfulDeployReportsNoFailure(): void
    {
        self::assertSame([], $this->deployExitingWith(0));
    }

    /** @return list<string> what the plugin wrote to stderr */
    private function deployExitingWith(int $exitCode): array
    {
        $disabled = getenv(self::DISABLE);
        \Safe\putenv(self::DISABLE);
        ProcessExecutor::$exitCode = $exitCode;
        $vendor                    = TempDir::create('skills-plugin-vendor');

        try {
            $vendor->mkdir('lts/php-qa-ci/.claude/skills');
            $vendor->write('lts/php-qa-ci/scripts/deploy-skills.bash', '');
            $io = new CapturingIO();
            new SkillsDeployPlugin()->deploySkills(new Event(new Composer(new Config(['vendor-dir' => $vendor->path]), new FakeRootPackage()), $io));
        } finally {
            $vendor->remove();
            ProcessExecutor::$exitCode = 1;
            if (\is_string($disabled)) {
                \Safe\putenv(self::DISABLE . '=' . $disabled);
            }
        }

        return $io->err;
    }
}
