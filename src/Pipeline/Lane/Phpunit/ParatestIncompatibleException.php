<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpunit;

use RuntimeException;

/**
 * The installed ParaTest cannot be given the lane's arguments; the message
 * names the limitation so the run fails with a reason, not ParaTest's usage error.
 *
 * @internal
 */
final class ParatestIncompatibleException extends RuntimeException
{
    /** @param string ...$flags the flags the lane passes and this ParaTest does not declare */
    public static function missingOptions(string $paratest, string ...$flags): self
    {
        return new self(\sprintf(
            'the installed %s does not accept %s, which the lane passes to it;'
            . ' use ParaTest 7 or later, or remove ParaTest to run with PHPUnit',
            $paratest,
            implode(', ', $flags),
        ));
    }

    public static function severalPaths(int $count): self
    {
        return new self(\sprintf(
            'ParaTest takes one path and %d were specified; run one path at a time,'
            . ' or remove ParaTest to run several paths with PHPUnit',
            $count,
        ));
    }
}
