<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support;

use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Tests\Support\PhpstanJsonRunFailure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The verdict BundledRulesDoNotCrashOnValidPhpTest reads from a phpstan.phar
 * run, over JSON captured from the shipped phar (paths shortened to /project).
 *
 * The phar writes "Result is incomplete" for every internal error it reports,
 * but its JSON `errors` list also carries general errors that come with no
 * such line: an ignoreErrors pattern nothing matched is one. A run whose only
 * check is the line passes with them.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class PhpstanJsonRunFailureTest extends TestCase
{
    private const string CAPTURED = __DIR__ . '/../../assets/PHPStan/PhpstanJsonRunFailure/';

    private const string INCOMPLETE_STDERR = "⚠️  Result is incomplete because of severe errors. ⚠️\n"
        . "   Fix these errors first and then re-run PHPStan\n"
        . "   to get all reported errors.\n";

    #[Test]
    public function aRunWithFindingsAndNoGeneralErrorIsNotAFailure(): void
    {
        self::assertNull(PhpstanJsonRunFailure::reason($this->captured('findings-only.json', '')));
    }

    #[Test]
    public function anInternalErrorIsAFailure(): void
    {
        self::assertSame(
            PhpstanCrash::INCOMPLETE_REASON,
            PhpstanJsonRunFailure::reason($this->captured('internal-error.json', self::INCOMPLETE_STDERR)),
        );
    }

    #[Test]
    public function aGeneralErrorWithoutTheIncompleteLineIsAFailure(): void
    {
        $reason = PhpstanJsonRunFailure::reason($this->captured('general-error-without-marker.json', ''));

        self::assertNotNull($reason, 'a general error in the JSON errors list passed unnoticed');
        self::assertStringContainsString('Ignored error pattern #^A pattern no error matches\.$# was not matched', $reason);
    }

    /** A run that exits 0 without a JSON report never reached a verdict, so it cannot be a clean analysis. */
    #[Test]
    #[DataProvider('outputsThatAreNotAReport')]
    public function aSuccessfulExitWithoutAJsonReportIsAFailure(string $stdout): void
    {
        self::assertNotNull(PhpstanJsonRunFailure::reason(new ProcessResultDto(0, $stdout, $stdout)));
    }

    /** @return iterable<string, array{string}> */
    public static function outputsThatAreNotAReport(): iterable
    {
        yield 'empty' => [''];
        yield 'not JSON' => ['PHP Fatal error: something went wrong'];
        yield 'truncated' => ['{"totals":{"errors":0,"file_errors":0},"files":'];
    }

    private function captured(string $file, string $stderr): ProcessResultDto
    {
        $stdout = \Safe\file_get_contents(self::CAPTURED . $file);

        return new ProcessResultDto(1, $stdout . $stderr, $stdout);
    }
}
