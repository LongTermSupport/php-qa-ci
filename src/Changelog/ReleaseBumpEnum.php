<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

/**
 * Which part of the version a release asks to move, by what its entries mean
 * to a consumer. The project's ReleaseVersionPolicy decides what that does to
 * the version: under semantic versioning a breaking change moves the major
 * (the minor while the major is 0); under a locked major it moves the minor.
 *
 * @api
 */
enum ReleaseBumpEnum
{
    /** A breaking change or a removal. */
    case Major;
    /** Features and behaviour changes. */
    case Minor;
    /** Fixes and security fixes only. */
    case Patch;
}
