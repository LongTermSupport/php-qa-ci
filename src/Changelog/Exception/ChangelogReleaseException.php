<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Exception;

use RuntimeException;

/**
 * A release step was asked for something it must refuse rather than guess at:
 * a composer.json naming no single release line, a malformed version or date,
 * nothing to release, a version that is already released, or an unusable entry.
 *
 * @api
 */
final class ChangelogReleaseException extends RuntimeException
{
}
