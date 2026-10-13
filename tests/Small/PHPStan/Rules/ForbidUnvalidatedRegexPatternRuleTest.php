<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ErrorHandlerRestoringFinallyCollector;
use LTS\PHPQA\PHPStan\Rules\ForbidUnvalidatedRegexPatternRule;
use LTS\PHPQA\PHPStan\Rules\RegexPatternOwnership;
use LTS\PHPQA\PHPStan\Rules\RegexPatternVariableWriteCollector;
use LTS\PHPQA\PHPStan\Rules\UnownedRegexPatternCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidUnvalidatedRegexPatternRule>
 */
#[CoversClass(ForbidUnvalidatedRegexPatternRule::class)]
#[CoversClass(UnownedRegexPatternCollector::class)]
#[CoversClass(RegexPatternVariableWriteCollector::class)]
#[CoversClass(RegexPatternOwnership::class)]
#[CoversClass(ErrorHandlerRestoringFinallyCollector::class)]
#[Medium]
final class ForbidUnvalidatedRegexPatternRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/UnvalidatedRegexPattern';

    private const string PREG_MATCH = 'preg_match';

    #[Test]
    public function aPatternTheCodeDoesNotOwnIsReportedWhereverNothingWouldNameIt(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [self::message(self::PREG_MATCH), 20],
                [self::message('Safe\preg_match'), 21],
                [self::message('preg_match_all'), 22],
                [self::message('preg_replace'), 23],
                [self::message('preg_replace_callback'), 24],
                [self::message('preg_split'), 25],
                [self::message('preg_grep'), 26],
                [self::message('preg_filter'), 27],
                [self::message('preg_replace_callback_array'), 28],
                [self::message('Safe\preg_replace'), 29],
                [self::message(self::PREG_MATCH), 30],
                [self::message(self::PREG_MATCH), 36],
                [self::message(self::PREG_MATCH), 41],
                [self::message(self::PREG_MATCH), 46],
                [self::message(self::PREG_MATCH), 47],
                [self::message(self::PREG_MATCH), 48],
                [self::message(self::PREG_MATCH), 49],
                [self::message(self::PREG_MATCH), 55],
                [self::message(self::PREG_MATCH), 64],
                [self::message(self::PREG_MATCH), 74],
                [self::message(self::PREG_MATCH), 87],
                [self::message(self::PREG_MATCH), 94],
                [self::message(self::PREG_MATCH), 104],
                [self::message(self::PREG_MATCH), 110],
                [self::message(self::PREG_MATCH), 116],
                [self::message(self::PREG_MATCH), 125],
                [self::message(self::PREG_MATCH), 131],
                [self::message(self::PREG_MATCH), 137],
                [self::message(self::PREG_MATCH), 138],
            ],
        );
    }

    /**
     * The fixture is loaded first so its class is known to the reflection provider, as a project's
     * autoloaded classes are: the patterns it holds in class constants are only literal once their
     * class can be read.
     */
    #[Test]
    public function aPatternBuiltFromLiteralsAndQuotedValuesOrCompiledUnderAHandlerIsNotReported(): void
    {
        require_once self::FIXTURES . '/NotReportable.php';

        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidUnvalidatedRegexPatternRule();
    }

    protected function getCollectors(): array
    {
        return [
            new UnownedRegexPatternCollector(self::createReflectionProvider()),
            new RegexPatternVariableWriteCollector(self::createReflectionProvider()),
            new ErrorHandlerRestoringFinallyCollector(),
        ];
    }

    private static function message(string $function): string
    {
        return $function . '() compiles a pattern that is not built from literal strings and preg_quote(), so a pattern that does not compile is reported as a bare PCRE warning that names neither the pattern nor where it came from. '
            . 'Compile it under an error handler that throws naming both, restored in a finally. See docs/phpstan-rules/forbid-unvalidated-regex-pattern.md.';
    }
}
