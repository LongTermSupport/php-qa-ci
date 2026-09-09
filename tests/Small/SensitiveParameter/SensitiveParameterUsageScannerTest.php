<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\SensitiveParameter;

use LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageResult;
use LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SensitiveParameterUsageScanner::class)]
#[CoversClass(SensitiveParameterUsageResult::class)]
#[Small]
final class SensitiveParameterUsageScannerTest extends TestCase
{
    private const string PROJECT_WITH    = __DIR__ . '/../../assets/sensitiveParameterUsage/projectWithAttribute';

    private const string PROJECT_WITHOUT = __DIR__ . '/../../assets/sensitiveParameterUsage/projectWithoutAttribute';

    private const string PROJECT_MULTIPLE = __DIR__ . '/../../assets/sensitiveParameterUsage/projectMultiple';

    private const string SRC_DIR = '/src';

    private SensitiveParameterUsageScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new SensitiveParameterUsageScanner();
    }

    #[Test]
    public function itFindsTheAttributeWhenAProjectUsesIt(): void
    {
        $result = $this->scanner->scan(self::PROJECT_WITH . self::SRC_DIR);

        self::assertSame(1, $result->count);
        self::assertTrue($result->hasUsage());
        self::assertCount(1, $result->locations);
        self::assertStringContainsString('Authenticator.php', $result->locations[0]);
    }

    #[Test]
    public function itReportsZeroWhenAProjectNeverUsesTheAttribute(): void
    {
        $result = $this->scanner->scan(self::PROJECT_WITHOUT . self::SRC_DIR);

        self::assertSame(0, $result->count);
        self::assertFalse($result->hasUsage());
        self::assertSame([], $result->locations);
    }

    #[Test]
    public function itDoesNotFalseMatchTheAttributeInComments(): void
    {
        // The without-attribute fixture mentions "#[\SensitiveParameter]" in a
        // docblock. An AST-based scan must ignore it; a naive text scan would not.
        $result = $this->scanner->scan(self::PROJECT_WITHOUT . self::SRC_DIR);

        self::assertSame(0, $result->count);
    }

    #[Test]
    public function mainReturnsZeroForAProjectThatUsesTheAttribute(): void
    {
        // Pass useCheck explicitly so this is deterministic regardless of the
        // ambient useSensitiveParameterCheck env var, which would otherwise
        // make main() skip.
        \Safe\ob_start();
        $exitCode = SensitiveParameterUsageScanner::main(self::PROJECT_WITH, useCheck: true);
        $output   = \Safe\ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('1', $output);
    }

    #[Test]
    public function mainReturnsOneForAProjectThatNeverUsesTheAttribute(): void
    {
        \Safe\ob_start();
        $exitCode = SensitiveParameterUsageScanner::main(self::PROJECT_WITHOUT, useCheck: true);
        $output   = \Safe\ob_get_clean();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No #[\SensitiveParameter]', $output);
        self::assertStringContainsString('->withSensitiveParameterCheck(false)', $output);
    }

    #[Test]
    public function mainIsANoOpWhenTheEscapeHatchDisablesTheCheck(): void
    {
        \Safe\ob_start();
        $exitCode = SensitiveParameterUsageScanner::main(self::PROJECT_WITHOUT, useCheck: false);
        $output   = \Safe\ob_get_clean();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('disabled', $output);
    }

    /**
     * Every location is reported as "<absolute-file-path>:<line>". The scanner
     * builds it as `$file . ':' . $line`, and downstream tooling (and humans
     * clicking the path) rely on that exact shape — a dropped separator, a
     * reversed order, or a missing line number would all break click-to-open.
     */
    #[Test]
    public function eachLocationIsTheAbsoluteFilePathFollowedByAColonAndLineNumber(): void
    {
        $result = $this->scanner->scan(self::PROJECT_WITH . self::SRC_DIR);

        self::assertCount(1, $result->locations);
        self::assertMatchesRegularExpression('#/Authenticator\.php:\d+$#', $result->locations[0]);
    }

    /**
     * The scan aggregates across every file in the tree AND every attribute
     * within a file, returning one location per occurrence. AlphaService carries
     * one usage and ZuluHandler two, so the total is exactly three — this pins
     * both the cross-file merge and the per-file multi-attribute collection
     * (an "only the first element" regression in either loop would under-count).
     */
    #[Test]
    public function itAggregatesEveryOccurrenceAcrossFilesAndWithinAFile(): void
    {
        $result = $this->scanner->scan(self::PROJECT_MULTIPLE . self::SRC_DIR);

        self::assertSame(3, $result->count);
        self::assertCount(3, $result->locations);

        $alphaHits = array_filter(
            $result->locations,
            static fn (string $location): bool => str_contains($location, 'AlphaService.php'),
        );
        $zuluHits = array_filter(
            $result->locations,
            static fn (string $location): bool => str_contains($location, 'ZuluHandler.php'),
        );
        self::assertCount(1, $alphaHits);
        self::assertCount(2, $zuluHits);
    }

    /**
     * Reported locations are sorted, so the scanner's output is deterministic
     * regardless of the order the filesystem iterator yields files. AlphaService
     * must therefore always precede ZuluHandler in the reported list.
     */
    #[Test]
    public function reportedLocationsAreInDeterministicSortedOrder(): void
    {
        $result = $this->scanner->scan(self::PROJECT_MULTIPLE . self::SRC_DIR);

        $sorted = $result->locations;
        sort($sorted);
        self::assertSame($sorted, $result->locations);
        self::assertStringContainsString('AlphaService.php', $result->locations[0]);
    }

    /**
     * The PASSED summary names the exact count and then lists each location on
     * its own "  - <location>" line. Pinning the full rendering guards the
     * human-facing report against a dropped prefix, a reversed concatenation, or
     * a miscounted total.
     */
    #[Test]
    public function mainPrintsTheExactPassSummaryIncludingEachLocationLine(): void
    {
        $location = $this->scanner->scan(self::PROJECT_WITH . self::SRC_DIR)->locations[0];

        \Safe\ob_start();
        SensitiveParameterUsageScanner::main(self::PROJECT_WITH, useCheck: true);
        $output = \Safe\ob_get_clean();

        $expected = \PHP_EOL
            . 'SensitiveParameter usage check PASSED — found 1 use(s) of #[\SensitiveParameter]:' . \PHP_EOL
            . '  - ' . $location . \PHP_EOL;

        self::assertSame($expected, $output);
    }

    /**
     * The disabled-branch message must name the exact escape-hatch env var and
     * value so a user reading it can reproduce the opt-out verbatim.
     */
    #[Test]
    public function mainPrintsTheExactDisabledMessage(): void
    {
        \Safe\ob_start();
        SensitiveParameterUsageScanner::main(self::PROJECT_WITHOUT, useCheck: false);
        $output = \Safe\ob_get_clean();

        $expected = \PHP_EOL
            . 'SensitiveParameter usage check is disabled for this project ('
            . 'useSensitiveParameterCheck=0) — skipping.' . \PHP_EOL;

        self::assertSame($expected, $output);
    }

    /**
     * The FAILED guidance is a security-baseline contract: it must render the
     * scanned directory, the worked #[\SensitiveParameter] example, and the exact
     * `->withSensitiveParameterCheck(false)` opt-out line. A corruption of any part
     * of that guidance (a dropped instruction, a reversed line) would silently
     * mislead a developer hitting the check, so the whole block is pinned exactly.
     */
    #[Test]
    public function mainPrintsTheExactFailureGuidanceMessage(): void
    {
        \Safe\ob_start();
        SensitiveParameterUsageScanner::main(self::PROJECT_WITHOUT, useCheck: true);
        $output = \Safe\ob_get_clean();

        $rule         = str_repeat('=', 78);
        $srcDirectory = self::PROJECT_WITHOUT . self::SRC_DIR;
        $expected     = \PHP_EOL
            . $rule . \PHP_EOL
            . 'SensitiveParameter usage check FAILED' . \PHP_EOL
            . $rule . \PHP_EOL
            . \PHP_EOL
            . 'No #[\SensitiveParameter] attribute was found anywhere in:' . \PHP_EOL
            . '  ' . $srcDirectory . \PHP_EOL
            . \PHP_EOL
            . 'PHP 8.2+ replaces a #[\SensitiveParameter] argument with a redacted' . \PHP_EOL
            . 'placeholder in stack traces, keeping passwords / tokens / secrets out of' . \PHP_EOL
            . 'logs and error reporters. Most projects handle such a value somewhere and' . \PHP_EOL
            . 'should mark that parameter, e.g.:' . \PHP_EOL
            . \PHP_EOL
            . '    public function login(string $user, #[\SensitiveParameter] string $password) {}' . \PHP_EOL
            . \PHP_EOL
            . 'If this project genuinely never handles a sensitive parameter, opt out by' . \PHP_EOL
            . 'adding the following to qaConfig/qa.php:' . \PHP_EOL
            . \PHP_EOL
            . '    ->withSensitiveParameterCheck(false)' . \PHP_EOL
            . \PHP_EOL
            . $rule . \PHP_EOL;

        self::assertSame($expected, $output);
    }
}
