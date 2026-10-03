<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Exception;

use RuntimeException;

/**
 * The git history a changelog decision needs is not there: a shallow clone,
 * a missing default branch or release tag, no merge base, or a git command
 * that failed. Fails the decision rather than letting it pass on no evidence.
 *
 * @api
 */
final class ChangelogHistoryException extends RuntimeException
{
}
