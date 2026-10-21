<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

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

    /** @return array<string, array{string}> */
    public static function provideNotAnUpgrade(): array
    {
        return [
            'downgrade' => ["  - Downgrading lts/php-qa-ci (1.3.0 => 1.2.0)\n"],
            'install'   => ["  - Installing lts/php-qa-ci (1.3.0)\n"],
            'removal'   => ["  - Removing lts/php-qa-ci (1.3.0)\n"],
            'nothing'   => ["Nothing to modify in lock file\n"],
        ];
    }

    #[Test]
    #[DataProvider('provideUnreachableSource')]
    public function aSourceComposerCouldNotReachIsNamed(string $line): void
    {
        self::assertSame($line, new UpdateDryRunOutput("Loading composer repositories with package information\n  " . $line . "\nUpdating dependencies\n")->unreachableSource());
    }

    /** @return array<string, array{string}> */
    public static function provideUnreachableSource(): array
    {
        return [
            'packagist from cache' => ['https://repo.packagist.org could not be fully loaded (curl error 6 while downloading https://repo.packagist.org/packages.json: Could not resolve host: repo.packagist.org), package information was loaded from the local cache and may be out of date'],
            'vcs from cache'       => ['Failed to update https://github.com/LongTermSupport/php-qa-ci.git, package information from this repository may be outdated'],
            'url not accessed'     => ["Warning: The 'https://example.invalid/packages.json' URL could not be accessed: curl error 6"],
        ];
    }

    #[Test]
    public function aDryRunThatReachedEverySourceNamesNone(): void
    {
        self::assertNull(new UpdateDryRunOutput("Loading composer repositories with package information\nUpdating dependencies\nNothing to modify in lock file\n")->unreachableSource());
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
}
