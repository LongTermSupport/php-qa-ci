<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidRepeatedStringLiteralRule;
use LTS\PHPQA\PHPStan\Rules\RepeatedStringLiteralCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidRepeatedStringLiteralRule>
 */
#[CoversClass(ForbidRepeatedStringLiteralRule::class)]
#[CoversClass(RepeatedStringLiteralCollector::class)]
#[Medium]
final class ForbidRepeatedStringLiteralRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/RepeatedStringLiteral';

    #[Test]
    public function aLiteralRepeatedThreeTimesIsFlaggedOnceWithItsLinesAndNestedClassesCountSeparately(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Repeats.php'],
            [
                ["String literal '8.5.10' appears 3 times in this class (lines 17, 17, 17); declare it once as a class constant.", 17],
                ["String literal 'inner' appears 3 times in this class (lines 40, 40, 40); declare it once as a class constant.", 40],
            ],
        );
    }

    #[Test]
    public function aClassUsingAConstantIsClean(): void
    {
        $this->analyse([self::FIXTURES . '/Clean.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidRepeatedStringLiteralRule();
    }
}
