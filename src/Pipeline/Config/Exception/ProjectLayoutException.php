<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Exception;

use RuntimeException;

/**
 * The project does not have the layout the pipeline requires.
 *
 * @internal
 */
final class ProjectLayoutException extends RuntimeException
{
    public static function missingDirectory(string $name, string $projectRoot): self
    {
        return new self(\sprintf(
            "You have no '%s' directory under %s. This is not supported: create at least an empty one, e.g. mkdir -p %s/%s",
            $name,
            $projectRoot,
            $projectRoot,
            $name,
        ));
    }

    public static function missingComposerJson(string $projectRoot): self
    {
        return new self(\sprintf('No readable composer.json under %s; the pipeline needs one to find the bin directory.', $projectRoot));
    }
}
