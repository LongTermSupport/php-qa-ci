<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ErrorHandlerInstallCollector;
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
#[CoversClass(ErrorHandlerInstallCollector::class)]
#[Medium]
final class ForbidUnvalidatedRegexPatternRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/UnvalidatedRegexPattern';

    private const string PREG_MATCH = 'preg_match';

    /** Loaded first, as the other fixture is, so the by-reference method it calls can be resolved. */
    #[Test]
    public function aPatternTheCodeDoesNotOwnIsReportedWhereverNothingWouldNameIt(): void
    {
        require_once self::FIXTURES . '/Reportable.php';

        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$this->message(self::PREG_MATCH), 20],
                [$this->message('Safe\preg_match'), 21],
                [$this->message('preg_match_all'), 22],
                [$this->message('preg_replace'), 23],
                [$this->message('preg_replace_callback'), 24],
                [$this->message('preg_split'), 25],
                [$this->message('preg_grep'), 26],
                [$this->message('preg_filter'), 27],
                [$this->message('preg_replace_callback_array'), 28],
                [$this->message('Safe\preg_replace'), 29],
                [$this->message(self::PREG_MATCH), 30],
                [$this->message(self::PREG_MATCH), 36],
                [$this->message(self::PREG_MATCH), 41],
                [$this->message(self::PREG_MATCH), 46],
                [$this->message(self::PREG_MATCH), 47],
                [$this->message(self::PREG_MATCH), 48],
                [$this->message(self::PREG_MATCH), 49],
                [$this->message(self::PREG_MATCH), 55],
                [$this->message(self::PREG_MATCH), 64],
                [$this->message(self::PREG_MATCH), 74],
                [$this->message(self::PREG_MATCH), 87],
                [$this->message(self::PREG_MATCH), 94],
                [$this->message(self::PREG_MATCH), 104],
                [$this->message(self::PREG_MATCH), 110],
                [$this->message(self::PREG_MATCH), 116],
                [$this->message(self::PREG_MATCH), 125],
                [$this->message(self::PREG_MATCH), 131],
                [$this->message(self::PREG_MATCH), 137],
                [$this->message(self::PREG_MATCH), 138],
                [$this->message(self::PREG_MATCH), 143],
                [$this->message(self::PREG_MATCH), 144],
                [$this->message(self::PREG_MATCH), 145],
                [$this->message(self::PREG_MATCH), 151],
                [$this->message(self::PREG_MATCH), 161],
                [$this->message(self::PREG_MATCH), 169],
                [$this->message(self::PREG_MATCH), 176],
                [$this->message(self::PREG_MATCH), 184],
                [$this->message(self::PREG_MATCH), 191],
                [$this->message(self::PREG_MATCH), 201],
                [$this->message(self::PREG_MATCH), 208],
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

    /** A script's top-level code is one body: a guard there counts only after a handler installed there. */
    #[Test]
    public function aTopLevelCallIsGuardedOnlyAfterATopLevelInstall(): void
    {
        $this->analyse([self::FIXTURES . '/TopLevel.php'], [[$this->message(self::PREG_MATCH), 9]]);
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
            new ErrorHandlerInstallCollector(),
        ];
    }

    private function message(string $function): string
    {
        return $function . '() compiles a pattern that is not built from literal strings and preg_quote(), so a pattern that does not compile is reported as a bare PCRE warning that names neither the pattern nor where it came from. '
            . 'Compile it under an error handler that throws naming both, restored in a finally. See docs/phpstan-rules/forbid-unvalidated-regex-pattern.md.';
    }
}
