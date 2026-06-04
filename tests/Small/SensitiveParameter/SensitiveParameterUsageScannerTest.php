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

    private SensitiveParameterUsageScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new SensitiveParameterUsageScanner();
    }

    #[Test]
    public function itFindsTheAttributeWhenAProjectUsesIt(): void
    {
        $result = $this->scanner->scan(self::PROJECT_WITH . '/src');

        self::assertSame(1, $result->count);
        self::assertTrue($result->hasUsage());
        self::assertCount(1, $result->locations);
        self::assertStringContainsString('Authenticator.php', $result->locations[0]);
    }

    #[Test]
    public function itReportsZeroWhenAProjectNeverUsesTheAttribute(): void
    {
        $result = $this->scanner->scan(self::PROJECT_WITHOUT . '/src');

        self::assertSame(0, $result->count);
        self::assertFalse($result->hasUsage());
        self::assertSame([], $result->locations);
    }

    #[Test]
    public function itDoesNotFalseMatchTheAttributeInComments(): void
    {
        // The without-attribute fixture mentions "#[\SensitiveParameter]" in a
        // docblock. An AST-based scan must ignore it; a naive text scan would not.
        $result = $this->scanner->scan(self::PROJECT_WITHOUT . '/src');

        self::assertSame(0, $result->count);
    }

    #[Test]
    public function mainReturnsZeroForAProjectThatUsesTheAttribute(): void
    {
        \Safe\ob_start();
        $exitCode = SensitiveParameterUsageScanner::main(self::PROJECT_WITH);
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
        self::assertStringContainsString('useSensitiveParameterCheck', $output);
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
}
