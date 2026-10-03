<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\AgentContext;

use LTS\PHPQA\AgentContext\AgentContextRegion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generated active-defences region inside a document an agent loads:
 * replaced whole between its markers, everything around it untouched, and
 * never guessed at when the markers are missing or malformed.
 *
 * @internal
 */
#[CoversClass(AgentContextRegion::class)]
#[Small]
final class AgentContextRegionTest extends TestCase
{
    private const string SECTION = AgentContextRegion::START . "\nnew\n" . AgentContextRegion::END;

    #[Test]
    public function theRegionIsReplacedWholeAndTheRestIsKept(): void
    {
        $document = "# Title\n\nBefore.\n" . AgentContextRegion::START . "\nold\nlines\n" . AgentContextRegion::END . "\nAfter.\n";

        self::assertSame("# Title\n\nBefore.\n" . self::SECTION . "\nAfter.\n", new AgentContextRegion()->replace($document, self::SECTION));
    }

    #[Test]
    public function aDocumentWithoutTheRegionOrWithAMalformedOneIsNotTouched(): void
    {
        $region = new AgentContextRegion();

        self::assertNull($region->replace("# Title\n", self::SECTION));
        self::assertNull($region->replace(AgentContextRegion::START . "\n", self::SECTION));
        self::assertNull($region->replace(AgentContextRegion::END . "\n" . AgentContextRegion::START . "\n", self::SECTION));
        self::assertNull($region->replace(self::SECTION . "\n" . self::SECTION . "\n", self::SECTION));
    }

    /**
     * Each malformation on its own, so no one check can stand in for another:
     * a second START after a complete region, a second END after it, an END
     * before the START, and an END with no START at all.
     */
    #[Test]
    public function eachMalformationAloneLeavesTheDocumentUntouched(): void
    {
        $region = new AgentContextRegion();

        self::assertNull($region->replace(self::SECTION . "\n" . AgentContextRegion::START . "\n", self::SECTION));
        self::assertNull($region->replace(self::SECTION . "\n" . AgentContextRegion::END . "\n", self::SECTION));
        self::assertNull($region->replace(AgentContextRegion::END . "\n" . AgentContextRegion::START . "\nx\n", self::SECTION));
        self::assertNull($region->replace(AgentContextRegion::END . "\n" . self::SECTION . "\n", self::SECTION));
        self::assertNull($region->replace("x\n" . AgentContextRegion::END . "\n", self::SECTION));
        self::assertNull($region->current(AgentContextRegion::START . "\n" . AgentContextRegion::START . "\n" . AgentContextRegion::END . "\n"));
    }

    #[Test]
    public function theCurrentRegionIsReadBackForADriftCheck(): void
    {
        $region = new AgentContextRegion();

        self::assertSame(self::SECTION, $region->current("x\n" . self::SECTION . "\ny\n"));
        self::assertNull($region->current("no region\n"));
    }
}
