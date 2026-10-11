<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidProcessStreamRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidProcessStreamRule>
 */
#[CoversClass(ForbidProcessStreamRule::class)]
#[Medium]
final class ForbidProcessStreamRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/ProcessStream';

    private const string STDERR = 'STDERR';

    private const string ADVICE =' inside a function reaches the process\'s own stream, which a test can neither capture nor assert, and a write to stderr stops Infection\'s initial test run. Take the stream as a parameter and let the entry script pass it. See docs/phpstan-rules/forbid-process-stream.md.';

    #[Test]
    public function everyStreamConstantAndEveryStreamOpenedByNameInsideAFunctionIsReported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [self::STDERR . self::ADVICE, 11],
                ['STDOUT' . self::ADVICE, 12],
                ['STDIN' . self::ADVICE, 13],
                [self::STDERR . self::ADVICE, 14],
                [self::STDERR . self::ADVICE, 18],
                ['php://stderr' . self::ADVICE, 24],
                ['PHP://STDOUT' . self::ADVICE, 25],
                ['php://stderr' . self::ADVICE, 26],
                ['php://stdin' . self::ADVICE, 27],
                ['php://fd/3' . self::ADVICE, 28],
                [self::STDERR . self::ADVICE, 34],
            ],
        );
    }

    #[Test]
    public function aHandedStreamOutputBufferedPrintingAStreamNameHeldAsDataAndARunnerExtensionAreNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    #[Test]
    public function anEntryScriptMayUseItsOwnProcessStreams(): void
    {
        $this->analyse([self::FIXTURES . '/Script.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidProcessStreamRule();
    }
}
