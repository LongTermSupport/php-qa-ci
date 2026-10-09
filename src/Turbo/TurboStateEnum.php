<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * Whether PHPStan ran with Turbo, as the PHPStan lane reports it.
 *
 * @internal
 */
enum TurboStateEnum
{
    /** Running, in the main process or in the workers. */
    case Enabled;

    /** Not running on a host php-qa-ci ships a build for: a missing, stale or unloadable binary. */
    case Missing;

    /** Not running on a host upstream publishes no build for, which is the expected fallback. */
    case NotBuiltForHost;

    /** diagnose said nothing about Turbo: it failed, or a PHPStan release changed its wording. */
    case Unknown;
}
