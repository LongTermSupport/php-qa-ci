<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

/**
 * What a project's constraint on one first-party package amounts to, set
 * against the newest release.
 *
 * @internal
 */
enum FirstPartyVerdictEnum: string
{
    /** The constraint admits the newest release, or nothing newer than the lock exists. */
    case Current = 'current';

    /** A loose constraint excludes a newer release: `composer update` can never reach it. */
    case Behind = 'behind';

    /** An exact pin: staying is deliberate. */
    case Pinned = 'pinned';

    /** The package follows a branch, which is judged by its head and not by release tags. */
    case FollowsBranch = 'followsBranch';
}
