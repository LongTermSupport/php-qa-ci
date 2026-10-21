<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Turbo;

use LTS\PHPQA\Turbo\TurboPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Pins the class "php-qa-ci decides the Turbo platform differently from the phpstan.phar that
 * loads the binary" (#268). The installer and the lane name a `turbo-ext/<platform>/` directory;
 * the shipped phar's own TurboExtensionSelector names the one it loads from. For every process a
 * real host can present, the two must name the same directory, or Turbo is installed where the
 * phar never looks. The phar's selector is asked in a separate PHP, so its class never meets the
 * PHPStan that the test suite's own dependencies load.
 *
 * @internal
 */
#[CoversClass(TurboPlatform::class)]
#[Large]
final class TurboPlatformAgreesWithShippedPharTest extends TestCase
{
    /** The shipped phar's selector source, which declares the class and needs nothing else. */
    private const string SELECTOR = 'phar://' . __DIR__ . '/../../../vendor-phar/phpstan.phar/src/Turbo/TurboExtensionSelector.php';

    /** PHP_OS_FAMILY on Linux. */
    private const string LINUX = 'Linux';

    /** A glibc PHP's maps: glibc's loader, no musl one. */
    private const string GLIBC_MAPS = "7f2a1c000000-7f2a1c028000 r--p 00000000 08:01 2 /usr/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2\n";

    /** A musl PHP's maps, as Alpine's loader appears in them. */
    private const string MUSL_MAPS = "7f2a1c000000-7f2a1c014000 r--p 00000000 08:01 2 /lib/ld-musl-x86_64.so.1\n";

    /**
     * Asks the phar's selector for the core path of each case: [os, machine, maps or null, has
     * /etc/alpine-release, thread-safe]. The binary's own name the phar builds inline from this
     * PHP's constants, so the core, whose name it does expose, is as far as a case can reach.
     */
    private const string ASK_THE_PHAR = 'namespace PHPStan\Turbo; require $argv[1];'
        . 'echo json_encode(array_map(static function (array $c): ?string {'
        . '$d = TurboExtensionSelector::resolvePlatformDirectory($c[0], $c[1], TurboExtensionSelector::resolveIsMusl($c[2] ?? false, $c[3]));'
        . 'return null === $d ? null : $d . "/" . TurboExtensionSelector::resolveCoreFileName($c[0], $c[4]); }, json_decode($argv[2], true)));';

    #[Test]
    public function everyHostProcessIsGivenTheCoreThePharLoads(): void
    {
        $cases = [];
        foreach (['x86_64', 'aarch64'] as $machine) {
            foreach ([self::GLIBC_MAPS, self::MUSL_MAPS, null] as $maps) {
                foreach ([false, true] as $alpine) {
                    foreach ([false, true] as $zts) {
                        $cases[] = [self::LINUX, $machine, $maps, $alpine, $zts];
                    }
                }
            }
        }

        // A thread-safe Mac is left out: the phar names a directory upstream ships no ZTS build
        // in, and php-qa-ci names none; neither finds a binary.
        $cases[] = ['Darwin', 'arm64', null, false, false];

        $process = new Process([\PHP_BINARY, '-d', 'display_errors=stderr', '-r', self::ASK_THE_PHAR, self::SELECTOR, \Safe\json_encode($cases)]);
        $process->mustRun();

        $pharCores = \Safe\json_decode($process->getOutput(), true);
        self::assertIsArray($pharCores);

        foreach ($cases as $index => [$os, $machine, $maps, $alpine, $zts]) {
            self::assertSame(
                $pharCores[$index],
                TurboPlatform::fromProcess($os, $machine, $maps, $alpine, $zts)->corePath(),
                'the phar and php-qa-ci disagree for ' . \Safe\json_encode($cases[$index]),
            );
        }
    }
}
