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
    public function theMissingOptionsMessageNamesTheParatestTheFlagsAndTheWayOut(): void
    {
        self::assertSame(
            'the installed ParaTest 6.11.1 does not accept --fail-on-risky, --strict-global-state,'
            . ' which the lane passes to it; use ParaTest 7 or later, or remove ParaTest to run with PHPUnit',
            ParatestIncompatibleException::missingOptions('ParaTest 6.11.1', ['--fail-on-risky', '--strict-global-state'])->getMessage(),
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
