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

    private const string PHP_STDERR = 'php://stderr';

    private const string ERROR_LOG = 'error_log() to the SAPI logger';

    private const string ADVICE =" inside a function reaches the process's own stream, which a test can neither capture nor assert, and a write to stderr stops Infection's initial test run. Take the stream as a parameter and let the entry script pass it. See docs/phpstan-rules/forbid-process-stream.md.";

    #[Test]
    public function everyStreamConstantAndEveryStreamOpenedByNameInsideAFunctionIsReported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [self::STDERR . self::ADVICE, 15],
                ['STDOUT' . self::ADVICE, 16],
                ['STDIN' . self::ADVICE, 17],
                [self::STDERR . self::ADVICE, 18],
                [self::STDERR . self::ADVICE, 22],
                [self::PHP_STDERR . self::ADVICE, 28],
                ['PHP://STDOUT' . self::ADVICE, 29],
                [self::PHP_STDERR . self::ADVICE, 30],
                ['php://stdin' . self::ADVICE, 31],
                ['php://fd/3' . self::ADVICE, 32],
                [self::PHP_STDERR . self::ADVICE, 34],
                ['new ConsoleOutput()' . self::ADVICE, 39],
                ['readline()' . self::ADVICE, 40],
                ['readline_add_history()' . self::ADVICE, 41],
                [self::ERROR_LOG . self::ADVICE, 42],
                [self::ERROR_LOG . self::ADVICE, 43],
                [self::STDERR . self::ADVICE, 49],
            ],
        );
    }

    #[Test]
    public function aHandedStreamBufferedOutputAFileLogAStreamNameAsDataAndARunnerExtensionAreNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    #[Test]
    public function anEntryScriptMayUseItsOwnProcessStreams(): void
    {
        $this->analyse([self::FIXTURES . '/Script.php'], []);
    }

    /**
     * An unqualified call means the namespace's own function of that name when there is one, as PHP
     * resolves it. The fixture is loaded first so its functions are known to the reflection provider,
     * as a project's autoloaded functions are: the test harness does not read the analysed file's own.
     */
    #[Test]
    public function aNamespacesOwnReadlineIsNotReportedAndPhpsQualifiedOneIs(): void
    {
        require_once self::FIXTURES . '/Shadowing.php';

        $this->analyse([self::FIXTURES . '/Shadowing.php'], [['readline()' . self::ADVICE, 28]]);
    }

    protected function getRule(): Rule
    {
        return new ForbidProcessStreamRule($this->createReflectionProvider());
    }
}
