<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpunit;

use LTS\PHPQA\Pipeline\Lane\Phpunit\ParatestCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParatestCapabilities::class)]
#[Small]
final class ParatestCapabilitiesTest extends TestCase
{
    /** The option lines of `paratest --help` for ParaTest 5, with the colour codes it prints. */
    private const string HELP_LEGACY = "Options:\n  \x1B[32m    --phpunit=PHPUNIT\x1B[39m                        The PHPUnit binary to execute.\n"
        . "  \x1B[32m    --log-junit=LOG-JUNIT\x1B[39m   Log test execution\n";

    /** ParaTest 6: neither --phpunit nor the strictness flags. */
    private const string HELP_MIDDLE = "Options:\n  --log-junit=LOG-JUNIT   Log\n  --stop-on-failure   Stop\n  --path=PATH  alias\n";

    /** ParaTest 7: the PHPUnit flags are forwarded, there is no --phpunit. */
    private const string HELP_CURRENT = "Options:\n  \x1B[32m    --fail-on-risky\x1B[39m   @see guide\n  --fail-on-warning  x\n  --strict-global-state  x\n";

    #[Test]
    public function anOptionNamedPhpunitMeansTheBinaryIsSelectedOnTheCommandLine(): void
    {
        $capabilities = ParatestCapabilities::fromHelp(self::HELP_LEGACY);

        self::assertTrue($capabilities->acceptsPhpunitOption);
        self::assertTrue($capabilities->isUsable());
    }

    #[Test]
    public function aHelpThatForwardsThePhpunitStrictnessFlagsIsCurrentAndTakesNoPhpunitOption(): void
    {
        $capabilities = ParatestCapabilities::fromHelp(self::HELP_CURRENT);

        self::assertFalse($capabilities->acceptsPhpunitOption);
        self::assertTrue($capabilities->isUsable());
    }

    #[Test]
    public function aHelpWithNeitherIsUnusable(): void
    {
        $capabilities = ParatestCapabilities::fromHelp(self::HELP_MIDDLE);

        self::assertFalse($capabilities->acceptsPhpunitOption);
        self::assertFalse($capabilities->isUsable());
    }

    #[Test]
    public function anEmptyHelpIsUnusable(): void
    {
        self::assertFalse(ParatestCapabilities::fromHelp('')->isUsable());
    }

    #[Test]
    public function aPhpunitArgvOptionIsNotThePhpunitOption(): void
    {
        $capabilities = ParatestCapabilities::fromHelp("  --phpunit-argv=X  internal\n");

        self::assertFalse($capabilities->acceptsPhpunitOption);
    }

    #[Test]
    public function theStrictnessFlagMustBeAWholeOptionName(): void
    {
        self::assertFalse(ParatestCapabilities::fromHelp("  --fail-on-risky-thing  x\n")->isUsable());
    }
}
