<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpstanCrash::class)]
#[UsesClass(ProcessResultDto::class)]
#[Small]
final class PhpstanCrashTest extends TestCase
{
    /** The line the shipped phar writes to stderr when it abandons an analysis. */
    private const string INCOMPLETE = "⚠️  Result is incomplete because of severe errors. ⚠️\n";

    /** What the shipped phar prints, and exits 1 on, for a config error: no analysis, no report. */
    private const string CONFIG_ERROR = "Invalid configuration:\nUnexpected item 'parameters › notARealParameter'.\n";

    /** A JSON report of one finding, counted under file_errors only. */
    private const string JSON_FINDINGS ='{"totals":{"errors":0,"file_errors":1},"files":{"/p/A.php":{"errors":1,"messages":[{"message":"Function nope not found.","line":3,"ignorable":true,"identifier":"function.notFound"}]}},"errors":[]}';

    #[Test]
    #[DataProvider('verdicts')]
    public function aRunThatReachedAVerdictIsNotACrash(int $exitCode, string $output): void
    {
        self::assertNull(PhpstanCrash::reason(new ProcessResultDto($exitCode, $output, $output)));
    }

    /** @return iterable<string, array{int, string}> */
    public static function verdicts(): iterable
    {
        yield 'clean' => [0, "[OK] No errors\n"];
        yield 'findings' => [1, " 12  Method foo() has no return type specified.\n [ERROR] Found 1 error\n"];
        yield 'several findings' => [1, " 12  Method foo() has no return type specified.\n 14  Method bar() has no return type specified.\n [ERROR] Found 2 errors\n"];
        yield 'a finding that quotes the words' => [1, " 3  'Result is incomplete' is a string.\n [ERROR] Found 1 error\n"];
    }

    /**
     * PHPStan exits 1 for a config error, a missing bootstrap file or a rule class it cannot load,
     * all before any analysis. Exit 1 is findings only when the output says it found some.
     */
    #[Test]
    #[DataProvider('exitOneWithoutFindings')]
    public function anExitOneThatReportsNoFindingsIsACrash(string $output): void
    {
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, PhpstanCrash::reason(new ProcessResultDto(1, $output, $output)));
    }

    /** @return iterable<string, array{string}> */
    public static function exitOneWithoutFindings(): iterable
    {
        yield 'a config error' => [self::CONFIG_ERROR];
        yield 'a missing bootstrap file' => ["In ResultCacheManager.php line 2357:\n  Could not read file: /p/does-not-exist.php\n\nanalyse [-c|--configuration CONFIGURATION] [--] [<paths>...]\n"];
        yield 'nothing at all' => [''];
        yield 'a findings count in prose, not the summary line' => ["Fatal: Found 3 errors in the container\n"];
    }

    #[Test]
    #[DataProvider('jsonVerdicts')]
    public function aJsonRunThatReachedAVerdictIsNotACrash(int $exitCode, string $json): void
    {
        self::assertNull(PhpstanCrash::jsonReason(new ProcessResultDto($exitCode, $json, $json)));
    }

    /** @return iterable<string, array{int, string}> */
    public static function jsonVerdicts(): iterable
    {
        yield 'clean' => [0, '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}'];
        yield 'file findings' => [1, self::JSON_FINDINGS];
        yield 'a general finding' => [1, '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Ignored error pattern #x# was not matched in reported errors."]}'];
    }

    #[Test]
    public function aJsonRunWithNoReportOnStdoutIsACrash(): void
    {
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, PhpstanCrash::jsonReason(new ProcessResultDto(1, self::CONFIG_ERROR, self::CONFIG_ERROR)));
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, PhpstanCrash::jsonReason(new ProcessResultDto(1, '[]', '[]')), 'JSON, but not a PHPStan report');
    }

    #[Test]
    public function aJsonRunThatReportsNothingFoundIsACrashThoughItExitsOne(): void
    {
        $empty = '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}';

        self::assertSame(PhpstanCrash::NO_REPORT_REASON, PhpstanCrash::jsonReason(new ProcessResultDto(1, $empty, $empty)));
    }

    #[Test]
    public function anAbandonedJsonRunIsACrash(): void
    {
        $json = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Internal error: boom"]}';

        self::assertSame(PhpstanCrash::INCOMPLETE_REASON, PhpstanCrash::jsonReason(new ProcessResultDto(1, $json . "\n" . self::INCOMPLETE, $json)));
        self::assertSame('PHPStan crashed (exit 255)', PhpstanCrash::jsonReason(new ProcessResultDto(255, 'Fatal', '')));
    }

    #[Test]
    public function anExitAboveOneIsACrashNamedByItsExitCode(): void
    {
        self::assertSame('PHPStan crashed (exit 255)', PhpstanCrash::reason(new ProcessResultDto(255, 'Fatal', '')));
    }

    #[Test]
    public function anAbandonedAnalysisIsACrashThoughItExitsOne(): void
    {
        $output = " Internal error: Unclosed '{' on line 49 while analysing file /p/A.php\n\n" . self::INCOMPLETE;

        self::assertSame(PhpstanCrash::INCOMPLETE_REASON, PhpstanCrash::reason(new ProcessResultDto(1, $output, '')));
    }

    #[Test]
    public function theMarkerIsReadFromStderrTooWhereJsonModeLeavesIt(): void
    {
        $json = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Internal error: boom"]}';

        self::assertSame(PhpstanCrash::INCOMPLETE_REASON, PhpstanCrash::reason(new ProcessResultDto(1, $json . "\n" . self::INCOMPLETE, $json)));
    }
}
