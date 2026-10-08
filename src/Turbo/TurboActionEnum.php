<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * What an install should do about PHPStan's Turbo binary for this host.
 *
 * @internal
 */
enum TurboActionEnum
{
    /** The binary for this host is installed, from the asset and digest the manifest names. */
    case Ready;

    /** Download the asset the decision names and install it. */
    case Fetch;

    /** Upstream builds no binary for this host; PHPStan runs here without Turbo. */
    case Unsupported;

    /** The checkout cannot give this host Turbo, and nothing here can fix that silently. */
    case Broken;
}
