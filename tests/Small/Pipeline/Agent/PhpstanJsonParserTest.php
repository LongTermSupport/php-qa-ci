<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent;

use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;
use LTS\PHPQA\Pipeline\Agent\PhpstanJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpstanJsonParser::class)]
#[UsesClass(FileErrorDto::class)]
#[UsesClass(UnreadableReportException::class)]
#[Small]
final class PhpstanJsonParserTest extends TestCase
{
    private const string FILE = '/project/src/Kernel.php';

    #[Test]
    public function aCleanReportYieldsNoFilesAndNoGlobalErrors(): void
    {
        $parsed = new PhpstanJsonParser()->parse('{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}');

        self::assertSame([], $parsed->files);
        self::assertSame([], $parsed->globalErrors);
        self::assertSame(0, $parsed->errorCount());
    }

    #[Test]
    public function eachFileKeepsItsOwnFindingsKeyedByItsAbsolutePath(): void
    {
        $parsed = new PhpstanJsonParser()->parse($this->twoFiles());

        self::assertSame([self::FILE, '/project/src/Other.php'], array_keys($parsed->files));
        self::assertCount(2, $parsed->files[self::FILE]);
        self::assertCount(1, $parsed->files['/project/src/Other.php']);
        self::assertSame(3, $parsed->errorCount());
    }

    #[Test]
    public function everyFieldPhpstanSuppliesIsCarriedThrough(): void
    {
        $parsed = new PhpstanJsonParser()->parse($this->twoFiles());
        $first  = $parsed->files[self::FILE][0];

        self::assertSame(89, $first->line);
        self::assertSame('Call to static method allClasses() on an unknown class.', $first->message);
        self::assertSame('class.notFound', $first->identifier);
        self::assertSame('Learn more at https://phpstan.org/user-guide/discovering-symbols', $first->tip);
    }

    #[Test]
    public function anOmittedLineIdentifierOrTipBecomesNullRatherThanBeingInvented(): void
    {
        $json = '{"totals":{"errors":0,"file_errors":1},"files":{"' . self::FILE . '":{"errors":1,"messages":[{"message":"bare","ignorable":true}]}},"errors":[]}';

        $only = new PhpstanJsonParser()->parse($json)->files[self::FILE][0];

        self::assertNull($only->line);
        self::assertNull($only->identifier);
        self::assertNull($only->tip);
        self::assertSame('bare', $only->message);
    }

    #[Test]
    public function aNonFileErrorIsKeptSeparatelyBecauseItBelongsToNoFile(): void
    {
        $json = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Child process error: out of memory"]}';

        $parsed = new PhpstanJsonParser()->parse($json);

        self::assertSame([], $parsed->files);
        self::assertSame(['Child process error: out of memory'], $parsed->globalErrors);
        self::assertSame(1, $parsed->errorCount());
    }

    #[Test]
    public function unparseableOutputIsRefusedRatherThanReportedAsClean(): void
    {
        $this->expectException(UnreadableReportException::class);
        $this->expectExceptionMessageMatches('/phpstan/i');

        new PhpstanJsonParser()->parse("PHP Fatal error: allowed memory size exhausted\n");
    }

    #[Test]
    public function jsonThatIsNotAPhpstanReportIsRefusedRatherThanReportedAsClean(): void
    {
        $this->expectException(UnreadableReportException::class);

        new PhpstanJsonParser()->parse('[1,2,3]');
    }

    #[Test]
    public function noiseBeforeTheReportIsSkippedSoAWarningOnStdoutCannotBreakTheParse(): void
    {
        $noise = "PHP Warning:  Module \"xml\" is already loaded in Unknown on line 0\n";

        $parsed = new PhpstanJsonParser()->parse($noise . '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}');

        self::assertSame(0, $parsed->errorCount());
    }

    private function twoFiles(): string
    {
        return '{"totals":{"errors":0,"file_errors":3},"files":{'
            . '"' . self::FILE . '":{"errors":2,"messages":['
            . '{"message":"Call to static method allClasses() on an unknown class.","line":89,"ignorable":true,"identifier":"class.notFound","tip":"Learn more at https://phpstan.org/user-guide/discovering-symbols"},'
            . '{"message":"Cannot call method that() on mixed.","line":90,"ignorable":true,"identifier":"method.nonObject"}'
            . ']},'
            . '"/project/src/Other.php":{"errors":1,"messages":['
            . '{"message":"Method foo() has no return type specified.","line":12,"ignorable":true,"identifier":"missingType.return"}'
            . ']}},"errors":[]}';
    }
}
