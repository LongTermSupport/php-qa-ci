<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

/**
 * Which part of the version a release moves. There is no major: the major is
 * the PHP line the branch targets, so a breaking change moves the minor.
 *
 * @api
 */
enum ReleaseBumpEnum
{
    /** Features, behaviour changes and breaking changes. */
    case Minor;
    /** Fixes and security fixes only. */
    case Patch;
}
