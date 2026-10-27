<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestIncompatibleException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParatestIncompatibleException::class)]
#[Small]
final class ParatestIncompatibleExceptionTest extends TestCase
{
    #[Test]
    public function theUnusableOptionsMessageNamesTheLimitationAndTheWayOut(): void
    {
        self::assertSame(
            'the installed ParaTest accepts neither --phpunit nor the PHPUnit strictness flags'
            . ' (--fail-on-risky and the like), so the lane cannot pass its arguments to it;'
            . ' upgrade ParaTest to 7 or later, or remove it to run with PHPUnit',
            ParatestIncompatibleException::unusableOptions()->getMessage(),
        );
    }

    #[Test]
    public function theSeveralPathsMessageStatesTheCount(): void
    {
        self::assertSame(
            'ParaTest takes one path and 3 were specified; run one path at a time,'
            . ' or remove ParaTest to run several paths with PHPUnit',
            ParatestIncompatibleException::severalPaths(3)->getMessage(),
        );
    }
}
