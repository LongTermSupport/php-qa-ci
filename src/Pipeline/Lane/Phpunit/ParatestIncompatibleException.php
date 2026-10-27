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
    public static function unusableOptions(): self
    {
        return new self(
            'the installed ParaTest accepts neither --phpunit nor the PHPUnit strictness flags'
            . ' (--fail-on-risky and the like), so the lane cannot pass its arguments to it;'
            . ' upgrade ParaTest to 7 or later, or remove it to run with PHPUnit',
        );
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
