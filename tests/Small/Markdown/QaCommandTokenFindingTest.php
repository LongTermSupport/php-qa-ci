<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Markdown;

use LTS\PHPQA\Markdown\QaCommandTokenFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(QaCommandTokenFinding::class)]
#[Small]
final class QaCommandTokenFindingTest extends TestCase
{
    private const string FILE = '.claude/agents/fixer.md';

    private const string COMMAND = '{bin}/qa -t allCs';

    private const string PROBLEM = "'allCs' is not a tool bin/qa accepts";

    #[Test]
    public function itCarriesTheHintWhenThereIsOne(): void
    {
        $finding = new QaCommandTokenFinding(self::FILE, 184, 'allCs', self::COMMAND, self::PROBLEM, 'did you mean -t allCS?');

        self::assertSame(self::FILE, $finding->file);
        self::assertSame(184, $finding->line);
        self::assertSame('allCs', $finding->token);
        self::assertSame(self::COMMAND, $finding->command);
        self::assertSame(self::PROBLEM, $finding->problem);
        self::assertSame('did you mean -t allCS?', $finding->hint);
        self::assertSame(self::FILE . ":184\n    " . self::COMMAND . ' — ' . self::PROBLEM . ' — did you mean -t allCS?', $finding->message());
    }

    #[Test]
    public function itPointsAtTheUsageTextWhenThereIsNoHint(): void
    {
        $finding = new QaCommandTokenFinding(self::FILE, 3, 'frobnicate', 'vendor/bin/qa -t frobnicate', "'frobnicate' is not a tool bin/qa accepts", null);

        self::assertNull($finding->hint);
        self::assertSame(self::FILE . ":3\n    vendor/bin/qa -t frobnicate — 'frobnicate' is not a tool bin/qa accepts — vendor/bin/qa -h lists every tool", $finding->message());
    }
}
