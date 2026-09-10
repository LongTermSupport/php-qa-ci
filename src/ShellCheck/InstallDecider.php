<?php

declare(strict_types=1);

namespace LTS\PHPQA\ShellCheck;

use LTS\PHPQA\ShellCheck\Dto\InstallDecisionDto;

/**
 * Whether a composer install or update should leave the vendored ShellCheck
 * alone, fetch a release, or refuse. Pure: it is handed what is on disk and
 * what the release API said, and decides nothing else.
 *
 * The two modes are deliberately asymmetric. An **install** never downloads:
 * the binary is committed, so its absence is a broken checkout, and fetching
 * behind the user's back would hide that. An **update** is the maintainer
 * path, and is the only place the pin ever moves.
 *
 * @api
 */
final readonly class InstallDecider
{
    public function forInstall(bool $binaryPresent, ?string $pinned): InstallDecisionDto
    {
        if ($binaryPresent && null !== $pinned) {
            return InstallDecisionDto::ready(\sprintf('ShellCheck %s present.', $pinned));
        }

        return InstallDecisionDto::broken(\sprintf(
            'the vendored ShellCheck is missing (%s), but it is committed to the repository',
            $binaryPresent ? 'no version pin recorded' : 'no binary',
        ));
    }

    public function forUpdate(bool $binaryPresent, ?string $pinned, ?string $latest): InstallDecisionDto
    {
        if (null === $latest) {
            // A rate limit or an outage must not fail the run or move the pin:
            // the committed binary is still the pinned one and still correct.
            return null === $pinned
                ? InstallDecisionDto::broken('there is no version pin and the GitHub release API could not be reached, so there is no version to fetch')
                : InstallDecisionDto::ready(\sprintf('ShellCheck stays at %s — could not reach the GitHub release API.', $pinned));
        }

        if ($binaryPresent && $latest === $pinned) {
            return InstallDecisionDto::ready(\sprintf('ShellCheck is already at the newest release (%s).', $pinned));
        }

        return InstallDecisionDto::fetch($latest, \sprintf('Updating ShellCheck %s -> %s...', $pinned ?? '(none)', $latest));
    }
}
