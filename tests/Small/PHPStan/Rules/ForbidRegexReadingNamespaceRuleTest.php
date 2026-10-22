<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidRegexReadingNamespaceRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the pattern is, and whether it is a constant, are read from PHPStan's types, so the rule is
 * pinned by analysing fixtures.
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidRegexReadingNamespaceRule>
 */
#[CoversClass(ForbidRegexReadingNamespaceRule::class)]
#[Medium]
final class ForbidRegexReadingNamespaceRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/RegexReadsNamespace';

    #[Test]
    public function aPatternThatReadsANamespaceDeclarationIsReportedWhereverItIsWritten(): void
    {
        require_once self::FIXTURES . '/Reportable.php';

        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [ForbidRegexReadingNamespaceRule::MESSAGE, 13],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 18],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 24],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 31],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 36],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 41],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 46],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 51],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 56],
                [ForbidRegexReadingNamespaceRule::MESSAGE, 61],
            ],
        );
    }

    #[Test]
    public function aPatternThatIsNotADeclarationOrCannotBeReadIsNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidRegexReadingNamespaceRule();
    }
}
