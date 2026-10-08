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
    private const string INCOMPLETE = "⚠️  Result is incomplete because of severe errors. ⚠️\n";

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
        yield 'a finding that quotes the words' => [1, " 3  'Result is incomplete' is a string.\n"];
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
