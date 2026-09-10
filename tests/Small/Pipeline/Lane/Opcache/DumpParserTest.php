<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Opcache;

use LTS\PHPQA\Pipeline\Lane\Opcache\Dto\ConstComparisonDto;
use LTS\PHPQA\Pipeline\Lane\Opcache\DumpParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DumpParser::class)]
#[UsesClass(ConstComparisonDto::class)]
#[Small]
final class DumpParserTest extends TestCase
{
    /** The file the fixture dump attributes its op_arrays to. */
    private const string FILE = '/project/src/Gateway/CreditNoteGateway.php';

    /** The method whose op_array carries the bad comparison in the fixture dump. */
    private const string METHOD = 'App\Gateway\CreditNoteGateway::findByReference';

    /** The after-optimizer dump PHP 8.5.10 prints for the mutated shape, trimmed to what matters. */
    private const string DUMP = <<<'DUMP'
        $_main:
             ; (lines=2, args=0, vars=0, tmps=0)
             ; (after optimizer)
             ; /project/src/Gateway/CreditNoteGateway.php:1-352
        0000 DECLARE_CLASS string("app\\gateway\\creditnotegateway")
        0001 RETURN int(1)

        App\Gateway\CreditNoteGateway::findByReference:
             ; (lines=71, args=1, vars=5, tmps=2)
             ; (after optimizer)
             ; /project/src/Gateway/CreditNoteGateway.php:129-167
        0000 CV0($externalReference) = RECV 1
        0051 T5 = TYPE_CHECK (null) CV4($creditNotes)
        0052 JMPZ T5 0055
        0053 T5 = IS_NOT_IDENTICAL null array(...)
        0054 JMPZ T5 0056
        0055 RETURN null
        0056 T6 = COUNT null
        0057 T5 = IS_SMALLER int(1) T6
        0058 JMPZ T5 0065
        LIVE RANGES:
             5: 0054 - 0055 (tmp/var)

        App\Gateway\CreditNoteGateway::other:
             ; (lines=3, args=0, vars=1, tmps=1)
             ; (after optimizer)
             ; /project/src/Gateway/CreditNoteGateway.php:170-175
        0000 T1 = IS_IDENTICAL CV0($x) array(...)
        0001 T1 = IS_EQUAL string("a b") string("a b")
        0002 RETURN T1
        DUMP;

    #[Test]
    public function aComparisonWithTwoConstantOperandsIsReportedWithItsFunctionAndLineRange(): void
    {
        $findings = new DumpParser()->parse(self::DUMP);

        self::assertCount(2, $findings);
        [$first, $second] = $findings;
        self::assertSame(self::FILE, $first->file);
        self::assertSame(self::METHOD, $first->function);
        self::assertSame(129, $first->lineStart);
        self::assertSame(167, $first->lineEnd);
        self::assertSame('IS_NOT_IDENTICAL null array(...)', $first->comparison);
        self::assertSame('App\Gateway\CreditNoteGateway::other', $second->function);
        self::assertSame('IS_EQUAL string("a b") string("a b")', $second->comparison);
    }

    #[Test]
    public function aComparisonWithAVariableOrTemporaryOperandIsNotAFinding(): void
    {
        $dump = <<<'DUMP'
            f:
                 ; (lines=1, args=1, vars=1, tmps=1)
                 ; (after optimizer)
                 ; /project/src/f.php:3-9
            0000 T1 = IS_IDENTICAL CV0($x) array(...)
            0001 T1 = IS_SMALLER int(1) T0
            0002 T1 = IS_NOT_IDENTICAL null CV0($x)
            0003 T1 = IS_EQUAL V2 null
            DUMP;

        self::assertSame([], new DumpParser()->parse($dump));
    }

    #[Test]
    public function anEmptyDumpHasNoFindings(): void
    {
        self::assertSame([], new DumpParser()->parse(''));
    }
}
