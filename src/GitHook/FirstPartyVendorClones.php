<?php

declare(strict_types=1);

namespace LTS\PHPQA\GitHook;

use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyVendors;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Tracking;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Which of the packages installed from source in vendor/ the pre-commit hook
 * (git-hooks/pre-commit-check-vendor-uncommitted) protects: the first-party
 * ones, as {@see FirstPartyVendors} decides them from the project's
 * composer.json. A third-party package installed from source may change its
 * own files (a Composer plugin writes generated code into its own tree) and is
 * never the project's to commit, so it is not protected.
 *
 * The answer goes to stdout, one package per line and nothing else; a note or
 * an error goes to stderr. A setting that cannot be read is an error, never a
 * silent "nothing is first party".
 *
 * @internal
 */
final readonly class FirstPartyVendorClones
{
    /** Exit code: the answer was printed. */
    public const int ANSWERED = 0;

    /** Exit code: composer.json or its setting cannot be read, so no answer can be given. */
    public const int UNREADABLE = 1;

    /**
     * @param string $projectRoot the directory holding the project's composer.json
     * @param string ...$packages vendor/package names of the clones found in vendor/
     */
    public static function main(OutputInterface $stdout, OutputInterface $stderr, string $projectRoot, string ...$packages): int
    {
        try {
            $vendors = FirstPartyVendors::fromManifest(Tracking::read($projectRoot . '/composer.json'));
        } catch (RuntimeException $runtimeException) {
            $stderr->writeln('ERROR: ' . $runtimeException->getMessage(), OutputInterface::OUTPUT_RAW);

            return self::UNREADABLE;
        }

        if ([] === $vendors->vendors) {
            $stderr->writeln(\sprintf(
                'NOTE: %s, so the pre-commit hook checks no vendor clone; %s.%s in composer.json names the vendors you author.',
                $vendors->describe(),
                'extra.' . FirstPartyVendors::EXTRA_NAMESPACE,
                FirstPartyVendors::EXTRA_KEY,
            ), OutputInterface::OUTPUT_RAW);

            return self::ANSWERED;
        }

        foreach ($packages as $package) {
            if ($vendors->owns($package)) {
                $stdout->writeln($package, OutputInterface::OUTPUT_RAW);
            }
        }

        return self::ANSWERED;
    }
}
