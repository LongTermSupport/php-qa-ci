<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Installer\InstallerOutputsInterface;
use LTS\PHPQA\Tests\Support\InstallerEntryPoints;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "an installer writes a path that nothing checks is ignored".
 *
 * {@see InstallerCreatedPathsAreIgnoredTest} checks each path an installer creates against this
 * package's .gitignore. That is only as complete as its list of paths, so each `bin/*-install` entry
 * point must resolve to a class that declares what it writes, and the test takes its rows from the
 * declarations. An installer with no declaration is found here instead of by a stray untracked entry
 * in a consumer's `vendor/lts/php-qa-ci/` checkout.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class InstallerEntryPointsDeclareTheirOutputsTest extends TestCase
{
    public function testThereAreEntryPointsToCheck(): void
    {
        self::assertNotSame([], InstallerEntryPoints::classes(), 'No bin/*-install entry point was found; the glob is wrong.');
    }

    public function testEveryInstallerEntryPointResolvesToAClassThatDeclaresItsOutputs(): void
    {
        $problems = [];
        foreach (InstallerEntryPoints::classes() as $entryPoint => $class) {
            if (null === $class) {
                $problems[] = $entryPoint . ' hands control to no class this test can find';

                continue;
            }

            if (!is_subclass_of($class, InstallerOutputsInterface::class)) {
                $problems[] = $entryPoint . ': ' . $class . ' does not implement ' . InstallerOutputsInterface::class . ', so nothing says what it writes';

                continue;
            }

            if ([] === $class::outputs()) {
                $problems[] = $entryPoint . ': ' . $class . ' declares no output, so nothing says what it writes';
            }
        }

        self::assertSame([], $problems);
    }
}
