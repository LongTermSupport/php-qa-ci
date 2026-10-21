<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ComposerPlugin;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;
use LTS\PHPQA\ComposerPlugin\ChildExit;
use LTS\PHPQA\ComposerPlugin\PhpStanGuardPlugin;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\CapturingIO;
use LTS\PHPQA\Tests\Assets\ComposerPlugin\FakeRootPackage;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpStanGuardPlugin::class)]
#[UsesClass(ChildExit::class)]
#[Small]
final class PhpStanGuardPluginTest extends TestCase
{
    private const string ACME_APP = 'acme/app';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../assets/ComposerPlugin/composer-stubs.php';
    }

    #[Test]
    public function subscribesToInstallAndUpdateWithLowPriority(): void
    {
        self::assertSame(
            [
                ScriptEvents::POST_INSTALL_CMD => ['validatePhpStan', -10],
                ScriptEvents::POST_UPDATE_CMD  => ['validatePhpStan', -10],
            ],
            PhpStanGuardPlugin::getSubscribedEvents(),
        );
    }

    #[Test]
    public function warnsWhenPhpStanIsADirectRequirement(): void
    {
        $io = $this->runValidate(new FakeRootPackage(self::ACME_APP, ['phpstan/phpstan' => '^2.0']));

        self::assertStringContainsString('MUST NOT be in your composer.json require', $io->allText());
        self::assertStringContainsString('composer remove phpstan/phpstan', $io->allText());
    }

    #[Test]
    public function warnsWhenPhpStanIsADevRequirement(): void
    {
        $io = $this->runValidate(new FakeRootPackage(self::ACME_APP, [], ['phpstan/phpstan' => '^2.0']));

        self::assertStringContainsString('MUST NOT be in your composer.json require-dev', $io->allText());
        self::assertStringContainsString('composer remove --dev phpstan/phpstan', $io->allText());
    }

    #[Test]
    public function isSilentWhenPhpStanIsNotADirectRequirement(): void
    {
        $io = $this->runValidate(new FakeRootPackage(self::ACME_APP));

        self::assertSame('', $io->allText());
    }

    /** A phar that dies on --version is a different fact from one whose version line is unreadable. */
    #[Test]
    public function aPharThatFailsItsVersionCheckIsReportedByItsExit(): void
    {
        self::assertSame(
            ['<warning>Could not determine PHPStan phar version: phpstan.phar --version failed with exit code 139, killed by signal 11 (SIGSEGV): it crashed with a segmentation fault, which is never retried past: halt, debug and report it, as https://github.com/LongTermSupport/php-qa-ci/blob/php8.5/CLAUDE/segfault-policy.md describes</warning>'],
            $this->validatePharExitingWith(139, ''),
        );
    }

    #[Test]
    public function aPharWhoseVersionLineIsUnreadableIsReportedAsSuch(): void
    {
        self::assertSame(
            ['<warning>Could not determine PHPStan phar version: phpstan.phar --version printed no version</warning>'],
            $this->validatePharExitingWith(0, 'PHPStan'),
        );
    }

    /** @return list<string> what the plugin wrote to stderr */
    private function validatePharExitingWith(int $exitCode, string $output): array
    {
        ProcessExecutor::$exitCode = $exitCode;
        ProcessExecutor::$output   = $output;
        $vendor                    = TempDir::create('phpstan-guard-vendor');

        try {
            $vendor->write('phpstan/extension-installer/src/GeneratedConfig.php', '<?php');
            $vendor->write('lts/php-qa-ci/vendor-phar/phpstan.phar', '');
            $io = new CapturingIO();
            new PhpStanGuardPlugin()->validatePhpStan(new Event(new Composer(new Config(['vendor-dir' => $vendor->path]), new FakeRootPackage(self::ACME_APP)), $io));
        } finally {
            $vendor->remove();
            ProcessExecutor::$exitCode = 1;
            ProcessExecutor::$output   = '';
        }

        return $io->err;
    }

    private function runValidate(FakeRootPackage $package): CapturingIO
    {
        $io = new CapturingIO();
        // vendor-dir points at a scratch path with no extension-installer
        // GeneratedConfig, so validatePharVersionConstraint returns early and
        // only the direct-requirement check runs.
        $composer = new Composer(new Config(['vendor-dir' => sys_get_temp_dir() . '/php-qa-ci-no-such-vendor']), $package);
        new PhpStanGuardPlugin()->validatePhpStan(new Event($composer, $io));

        return $io;
    }
}
