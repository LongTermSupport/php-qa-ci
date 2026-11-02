<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\UpdateDryRunOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading what `composer update --dry-run` printed. The samples are Composer
 * 2's own wording.
 *
 * @internal
 */
#[CoversClass(UpdateDryRunOutput::class)]
#[CoversClass(StaleDependencyDto::class)]
#[Small]
final class UpdateDryRunOutputTest extends TestCase
{
    private const string SELF = 'lts/php-qa-ci';

    private const string SELF_UPGRADE = "  - Upgrading lts/php-qa-ci (dev-php8.5 93f1ae4 => dev-php8.5 606a9f6)\n";

    private const string FIRST_REFUSED ='acme/one';

    private const string COOLDOWN_PROBLEM_ONE = "  Problem 1\n    - Root composer.json requires acme/one ^1.0, found acme/one[1.0.0] but these were not loaded, because they are still in the cooldown period configured in \"policy.cooldown\".\n";

    #[Test]
    public function anUpgradeOfANamedPackageIsStaleOnceThoughComposerPrintsItTwice(): void
    {
        $output = new UpdateDryRunOutput("Lock file operations: 0 installs, 1 update, 0 removals\n" . self::SELF_UPGRADE . "Package operations: 0 installs, 1 update, 0 removals\n" . self::SELF_UPGRADE);

        self::assertEquals([new StaleDependencyDto(self::SELF, 'dev-php8.5 93f1ae4', 'dev-php8.5 606a9f6')], $output->staleAmong(self::SELF));
    }

    #[Test]
    public function anUpgradeOfAPackageNotNamedIsNotStale(): void
    {
        $output = new UpdateDryRunOutput("  - Upgrading psr/log (3.0.0 => 3.0.2)\n" . self::SELF_UPGRADE);

        self::assertSame([self::SELF], array_map(static fn (StaleDependencyDto $stale): string => $stale->name, $output->staleAmong(self::SELF)));
    }

    #[Test]
    public function aTaggedUpgradeKeepsComposersVersions(): void
    {
        $output = new UpdateDryRunOutput("  - Upgrading acme/one (1.2.0 => 1.3.1)\n");

        self::assertEquals([new StaleDependencyDto('acme/one', '1.2.0', '1.3.1')], $output->staleAmong('acme/one'));
    }

    /** A downgrade, an install or a removal is not "a newer version". */
    #[Test]
    #[DataProvider('provideNotAnUpgrade')]
    public function nothingButAnUpgradeIsStale(string $printed): void
    {
        self::assertSame([], new UpdateDryRunOutput($printed)->staleAmong(self::SELF));
    }

    /** @return Iterator<string, array{string}> */
    public static function provideNotAnUpgrade(): Iterator
    {
        yield 'downgrade' => ["  - Downgrading lts/php-qa-ci (1.3.0 => 1.2.0)\n"];
        yield 'install' => ["  - Installing lts/php-qa-ci (1.3.0)\n"];
        yield 'removal' => ["  - Removing lts/php-qa-ci (1.3.0)\n"];
        yield 'nothing to modify' => ["Nothing to modify in lock file\n"];
    }

    #[Test]
    #[DataProvider('provideUnreachableSource')]
    public function aSourceComposerCouldNotReachIsNamed(string $line): void
    {
        self::assertSame($line, new UpdateDryRunOutput("Loading composer repositories with package information\n  " . $line . "\nUpdating dependencies\n")->unreachableSource());
    }

    /** @return Iterator<string, array{string}> */
    public static function provideUnreachableSource(): Iterator
    {
        yield 'packagist from cache' => ['https://repo.packagist.org could not be fully loaded (curl error 6 while downloading https://repo.packagist.org/packages.json: Could not resolve host: repo.packagist.org), package information was loaded from the local cache and may be out of date'];
        yield 'vcs from cache' => ['Failed to update https://github.com/LongTermSupport/php-qa-ci.git, package information from this repository may be outdated'];
        yield 'url not accessed' => ["Warning: The 'https://example.invalid/packages.json' URL could not be accessed: curl error 6"];
    }

    #[Test]
    public function aDryRunThatReachedEverySourceNamesNone(): void
    {
        self::assertNull(new UpdateDryRunOutput("Loading composer repositories with package information\nUpdating dependencies\nNothing to modify in lock file\n")->unreachableSource());
    }

    /** The lines are the ones Composer 2.10 printed for a real project's dry run, plain and coloured. */
    #[Test]
    #[DataProvider('provideAnAnswer')]
    public function composerAnsweredWhenItPrintedOneOfItsOwnResolverLines(string $printed): void
    {
        self::assertTrue(new UpdateDryRunOutput($printed)->composerAnswered());
    }

    /** @return Iterator<string, array{string}> */
    public static function provideAnAnswer(): Iterator
    {
        yield 'up to date' => ["Loading composer repositories with package information\nUpdating dependencies\nNothing to modify in lock file\nInstalling dependencies from lock file (including require-dev)\nNothing to install, update or remove\n"];
        yield 'stale' => ["Updating dependencies\nLock file operations: 0 installs, 1 update, 0 removals\n" . self::SELF_UPGRADE];
        yield 'up to date with a vendor directory to install' => ["Updating dependencies\nNothing to modify in lock file\nPackage operations: 1 install, 0 updates, 0 removals\n  - Installing lts/php-qa-ci (dev-php8.5 45463f4)\n"];
        yield 'only the resolver heading' => ["Updating dependencies\n"];
        yield 'coloured, with the progress overwrite Composer prints' => ["\e[32mLoading composer repositories with package information\e[39m\n\x08\x08\x08   \x08\x08\x08\e[32mUpdating dependencies\e[39m\nNothing to modify in lock file\n"];
        yield 'indented' => ["   Updating dependencies   \n"];
    }

    #[Test]
    #[DataProvider('provideNotAnAnswer')]
    public function composerDidNotAnswerWhenNoneOfItsResolverLinesWerePrinted(string $printed): void
    {
        self::assertFalse(new UpdateDryRunOutput($printed)->composerAnswered());
    }

    /** @return Iterator<string, array{string}> */
    public static function provideNotAnAnswer(): Iterator
    {
        yield 'a shell shim shown as text' => ["#!/bin/sh\nexec /usr/bin/composer \"\$@\"\n"];
        yield 'no output at all' => [''];
        yield 'blank lines' => ["\n  \n"];
        yield 'only the loading line' => ["Loading composer repositories with package information\n"];
        yield 'the marker inside other text' => ["echo 'Nothing to modify in lock file'\n"];
        yield 'an exception block' => ["\nIn VcsRepository.php line 193:\n\n  No driver found\n"];
    }

    #[Test]
    public function aColouredUpgradeIsStaleLikeAPlainOne(): void
    {
        $output = new UpdateDryRunOutput("  - Upgrading \e[32mlts/php-qa-ci\e[39m (\e[33mdev-php8.5 93f1ae4\e[39m => \e[33mdev-php8.5 606a9f6\e[39m)\n");

        self::assertEquals([new StaleDependencyDto(self::SELF, 'dev-php8.5 93f1ae4', 'dev-php8.5 606a9f6')], $output->staleAmong(self::SELF));
    }

    #[Test]
    public function whatWasPrintedIsItsFirstLine(): void
    {
        self::assertSame('exec /usr/bin/composer "$@"', new UpdateDryRunOutput("\n  exec /usr/bin/composer \"\$@\"  \nmore\n")->printedSummary());
    }

    #[Test]
    public function whatWasPrintedIsNothingWhenNothingWas(): void
    {
        self::assertSame('nothing', new UpdateDryRunOutput("\n \n")->printedSummary());
    }

    #[Test]
    public function aLongFirstLineIsCutShort(): void
    {
        $summary = new UpdateDryRunOutput(str_repeat('x', 500))->printedSummary();

        self::assertSame(str_repeat('x', 100) . '...', $summary);
    }

    #[Test]
    public function aFailureIsTheMessageOfTheExceptionComposerPrinted(): void
    {
        $output = new UpdateDryRunOutput(<<<'OUT'
            Loading composer repositories with package information

            In VcsRepository.php line 193:

              No driver found to handle VCS repository /srv/gone/php-qa-ci


            update [--with WITH] [--dry-run] [--] [<packages>...]

            OUT);

        self::assertSame('No driver found to handle VCS repository /srv/gone/php-qa-ci', $output->failureReason());
    }

    #[Test]
    public function aWrappedExceptionMessageIsJoinedOntoOneLine(): void
    {
        $output = new UpdateDryRunOutput("\nIn VcsRepository.php line 193:\n\n  No driver found to handle VCS repository /workspace/a-long  \n  -path/php-qa-ci  \n\n\nupdate [--with WITH]\n");

        self::assertSame('No driver found to handle VCS repository /workspace/a-long -path/php-qa-ci', $output->failureReason());
    }

    #[Test]
    public function aFailureWithoutAnExceptionBlockIsItsLastLine(): void
    {
        self::assertSame('Could not resolve host: github.com', new UpdateDryRunOutput("Loading composer repositories\n  Could not resolve host: github.com  \n\n")->failureReason());
    }

    #[Test]
    public function aFailureThatPrintedNothingSaysSo(): void
    {
        self::assertSame('no output', new UpdateDryRunOutput("\n \n")->failureReason());
    }

    #[Test]
    public function composersOwnCooldownRefusalNamesTheRefusedPackage(): void
    {
        $printed = <<<'OUT'
            Loading composer repositories with package information
            20 package version(s) withheld by the cooldown policy (run with -vv to list them).
            Updating dependencies
            Your requirements could not be resolved to an installable set of packages.

              Problem 1
                - Root composer.json requires acme/library ^85.10, found acme/library[85.10.0, 85.10.1, 85.10.2, 85.10.3] but these were not loaded, because they are still in the cooldown period configured in "policy.cooldown" (available in 2 days 22 hours). To exempt the package from the cooldown policy, add it to the "policy.cooldown.ignore" config, or run the command with --no-cooldown for a one-off bypass. To turn the feature off entirely, you can set "policy.cooldown.block" to false.

            OUT;

        self::assertSame(['acme/library'], new UpdateDryRunOutput($printed)->cooldownRefusedPackages());
    }

    #[Test]
    public function aCooldownProblemFollowedByAConflictProblemIsNotACooldownRefusal(): void
    {
        $printed = self::COOLDOWN_PROBLEM_ONE . "\n  Problem 2\n    - Root composer.json requires acme/other ^1.0 but it conflicts with your root composer.json require (^2.0).\n";

        self::assertNull(new UpdateDryRunOutput($printed)->cooldownRefusedPackages());
    }

    #[Test]
    public function aConflictAndACooldownReasonUnderOneProblemIsNotACooldownRefusal(): void
    {
        $printed = "  Problem 1\n    - Root composer.json requires acme/one ^1.0 but it conflicts with your root composer.json require (^2.0).\n"
            . "    - Root composer.json requires acme/two ^1.0, found acme/two[1.0.0] but these were not loaded, because they are still in the cooldown period configured in \"policy.cooldown\".\n";

        self::assertNull(new UpdateDryRunOutput($printed)->cooldownRefusedPackages());
    }

    #[Test]
    public function refusedPackagesAreNamedInLowerCaseOncePerPackageInOrder(): void
    {
        $printed = self::COOLDOWN_PROBLEM_ONE
            . "\n  Problem 2\n    - Root composer.json requires Acme/Two ^1.0, found Acme/Two[1.0.0] but these were not loaded, because they are still in the cooldown period configured in \"policy.cooldown\".\n"
            . "\n  Problem 3\n    - Root composer.json requires acme/one ^1.0, found ACME/ONE[1.0.0] but these were not loaded, because they are still in the cooldown period configured in \"policy.cooldown\".\n";

        self::assertSame([self::FIRST_REFUSED, 'acme/two'], new UpdateDryRunOutput($printed)->cooldownRefusedPackages());
    }

    #[Test]
    public function outputWithNoProblemIsNotACooldownRefusal(): void
    {
        self::assertNull(new UpdateDryRunOutput("20 package version(s) withheld by the cooldown policy (run with -vv to list them).\nNothing to modify in lock file\n")->cooldownRefusedPackages());
    }
}
