<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Cli;

use LTS\PHPQA\Pipeline\Cli\Exception\UsageException;

/**
 * A `-p` path may be project-relative (humans) or absolute under the project
 * root (editor and agent hooks). The result is always project-relative; the
 * root itself, or anything outside it, is refused.
 *
 * @internal
 */
final readonly class PathNormaliser
{
    public function normalise(string $path, string $projectRoot): string
    {
        $projectRoot = rtrim($projectRoot, '/');
        $trimmed     = rtrim($path, '/');

        if (!str_starts_with($trimmed, '/')) {
            if ('' === $trimmed || '.' === $trimmed) {
                throw UsageException::plain(\sprintf("\nERROR:\n-p must name a path inside the project, not the project root itself (%s)\n", $projectRoot));
            }

            return $trimmed;
        }

        if ($trimmed === $projectRoot) {
            throw UsageException::plain(\sprintf("\nERROR:\n-p must name a path inside the project, not the project root itself (%s)\n", $projectRoot));
        }

        if (!str_starts_with($trimmed, $projectRoot . '/')) {
            throw UsageException::plain(\sprintf("\nERROR:\n-p path %s is outside the project root %s\n", $path, $projectRoot));
        }

        return substr($trimmed, \strlen($projectRoot) + 1);
    }
}
