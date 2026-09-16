<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent;

use LTS\PHPQA\Pipeline\Agent\ArkitectJsonParser;
use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\ParsedArchReportDto;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ArkitectJsonParser::class)]
#[UsesClass(FileErrorDto::class)]
#[UsesClass(ParsedArchReportDto::class)]
#[UsesClass(UnreadableReportException::class)]
#[Small]
final class ArkitectJsonParserTest extends TestCase
{
    private const string BADLY_NAMED = 'ArkProbe\BadlyNamed';

    private const string SUFFIX_RULE = 'should have a name that matches *Interface because an Interface suffix makes the symbol kind obvious at every use site';

    #[Test]
    public function aCleanRunYieldsNoClassesAtAll(): void
    {
        $parsed = new ArkitectJsonParser()->parse('{"totalViolations": 0, "details": []}');

        self::assertSame([], $parsed->classes);
        self::assertSame(0, $parsed->violationCount());
    }

    #[Test]
    public function eachViolatingClassKeepsItsOwnMessagesKeyedByFullyQualifiedName(): void
    {
        $parsed = new ArkitectJsonParser()->parse($this->twoClasses());

        self::assertSame([self::BADLY_NAMED, 'ArkProbe\Sub\AlsoBad'], array_keys($parsed->classes));
        self::assertSame(3, $parsed->violationCount());
        self::assertCount(2, $parsed->classes[self::BADLY_NAMED]);
    }

    #[Test]
    public function aViolationCarriesTheMessageAndNothingInvented(): void
    {
        $only = new ArkitectJsonParser()->parse($this->twoClasses())->classes['ArkProbe\Sub\AlsoBad'][0];

        self::assertSame(self::SUFFIX_RULE, $only->message);
        self::assertNull($only->line, 'PHPArkitect reports per class and gives no line');
        self::assertNull($only->identifier, 'PHPArkitect has no stable rule identifier to record');
        self::assertNull($only->tip);
    }

    #[Test]
    public function theProgressBarPrecedingTheReportIsSkipped(): void
    {
        $noise = "analyze class set /project/src\n\n   0/197 [>------]   0%\n 197/197 [=======] 100%\n\n";

        $parsed = new ArkitectJsonParser()->parse($noise . $this->twoClasses() . "\n⚠️ 3 violations detected!\n");

        self::assertSame(3, $parsed->violationCount());
    }

    #[Test]
    public function theTrailerAfterTheReportIsSkipped(): void
    {
        $parsed = new ArkitectJsonParser()->parse('{"totalViolations": 0, "details": []}' . "\n✅ No violations detected\n⏱️ Execution time: 0.90\n");

        self::assertSame(0, $parsed->violationCount());
    }

    #[Test]
    public function aMessagelessEntryIsDroppedRatherThanRecordedAsABlankViolation(): void
    {
        $json = \Safe\json_encode(['totalViolations' => 1, 'details' => [self::BADLY_NAMED => [['error' => '']]]]);

        $parsed = new ArkitectJsonParser()->parse($json);

        self::assertSame([], $parsed->classes, 'a class whose only entry carries no message has nothing to act on');
    }

    #[Test]
    public function aBraceInTheBannerDoesNotBecomeTheStartOfTheReport(): void
    {
        $banner = "PHPArkitect 1.3.0.0\nConfig file '/project/{weird}/phparkitect.php' found\n\n";

        $parsed = new ArkitectJsonParser()->parse($banner . $this->twoClasses());

        self::assertSame(3, $parsed->violationCount(), 'the object starts at the brace nearest the key, not the first in the output');
    }

    #[Test]
    public function aBraceInTheTrailerDoesNotTruncateTheReport(): void
    {
        $parsed = new ArkitectJsonParser()->parse($this->twoClasses() . "\n⚠️ see {the docs} for help\n");

        self::assertSame(3, $parsed->violationCount(), 'the object ends at the last closing brace in the output');
    }

    #[Test]
    public function aKeyWithNoObjectAroundItIsRefused(): void
    {
        $this->expectException(UnreadableReportException::class);
        $this->expectExceptionMessageMatches('/not inside a JSON object/');

        new ArkitectJsonParser()->parse('"totalViolations": 3}');
    }

    #[Test]
    public function anObjectThatIsNeverClosedIsRefused(): void
    {
        $this->expectException(UnreadableReportException::class);
        $this->expectExceptionMessageMatches('/never closed/');

        new ArkitectJsonParser()->parse('{"totalViolations": 3, "details": [');
    }

    #[Test]
    public function aClosingBraceBeforeTheObjectStartsDoesNotCloseIt(): void
    {
        $this->expectException(UnreadableReportException::class);
        $this->expectExceptionMessageMatches('/never closed/');

        new ArkitectJsonParser()->parse('} earlier noise {"totalViolations": 0');
    }

    #[Test]
    public function aBraceInsideAViolationMessageDoesNotCloseTheObjectEarly(): void
    {
        $json = \Safe\json_encode([
            'totalViolations' => 1,
            'details'         => [self::BADLY_NAMED => [['error' => 'should not use array{foo: string} shapes here']]],
        ]);

        $parsed = new ArkitectJsonParser()->parse($json);

        self::assertSame(1, $parsed->violationCount());
        self::assertSame('should not use array{foo: string} shapes here', $parsed->classes[self::BADLY_NAMED][0]->message);
    }

    #[Test]
    public function aDetailsValueThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(UnreadableReportException::class);
        $this->expectExceptionMessageMatches('/details/');

        new ArkitectJsonParser()->parse('{"totalViolations": 1, "details": "nope"}');
    }

    #[Test]
    public function aViolationListThatIsNotAnArrayIsSkippedRatherThanCrashing(): void
    {
        $parsed = new ArkitectJsonParser()->parse('{"totalViolations": 1, "details": {"App\\\Thing": "not a list"}}');

        self::assertSame([], $parsed->classes);
    }

    #[Test]
    public function unparseableOutputIsRefusedRatherThanReportedAsClean(): void
    {
        $this->expectException(UnreadableReportException::class);
        $this->expectExceptionMessageMatches('/arkitect/i');

        new ArkitectJsonParser()->parse("PHP Fatal error: config not found\n");
    }

    #[Test]
    public function jsonWithoutTheTotalIsRefusedRatherThanReportedAsClean(): void
    {
        $this->expectException(UnreadableReportException::class);

        new ArkitectJsonParser()->parse('{"something": "else"}');
    }

    /** Built through json_encode so the fixture cannot disagree with the encoder the phar uses. */
    private function twoClasses(): string
    {
        return \Safe\json_encode([
            'totalViolations' => 3,
            'details'         => [
                self::BADLY_NAMED      => [
                    ['error' => self::SUFFIX_RULE],
                    ['error' => 'should be final because a non-final service invites inheritance nobody designed'],
                ],
                'ArkProbe\Sub\AlsoBad' => [['error' => self::SUFFIX_RULE]],
            ],
        ]);
    }
}
