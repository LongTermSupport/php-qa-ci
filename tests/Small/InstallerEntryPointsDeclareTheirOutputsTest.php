<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

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
    /** The interface an installer implements to say what it writes. */
    private const string DECLARES_OUTPUTS = 'LTS\PHPQA\Installer\InstallerOutputs';

    /** The class an entry point hands control to: `\Some\Name::main(`. */
    private const string ENTRY_CLASS = '/(\\\\LTS\\\\PHPQA\\\\[A-Za-z\\\\]+)::main\(/';

    public function testThereAreEntryPointsToCheck(): void
    {
        self::assertNotSame([], $this->entryPoints(), 'No bin/*-install entry point was found; the glob is wrong.');
    }

    public function testEveryInstallerEntryPointResolvesToAClassThatDeclaresItsOutputs(): void
    {
        $problems = [];
        foreach ($this->entryPoints() as $entryPoint) {
            $class = $this->classOf($entryPoint);
            if (null === $class) {
                $problems[] = basename($entryPoint) . ' hands control to no class this test can find';

                continue;
            }

            if (!is_subclass_of($class, self::DECLARES_OUTPUTS)) {
                $problems[] = basename($entryPoint) . ': ' . $class . ' does not implement ' . self::DECLARES_OUTPUTS . ', so nothing says what it writes';
            }
        }

        self::assertSame([], $problems);
    }

    /** @return list<string> */
    private function entryPoints(): array
    {
        $files = \Safe\glob(\dirname(__DIR__, 2) . '/bin/*-install');
        sort($files);

        return $files;
    }

    /** @return class-string|null */
    private function classOf(string $entryPoint): ?string
    {
        if (1 !== \Safe\preg_match(self::ENTRY_CLASS, \Safe\file_get_contents($entryPoint), $match) || !isset($match[1])) {
            return null;
        }

        $class = ltrim($match[1], '\\');

        return class_exists($class) ? $class : null;
    }
}
