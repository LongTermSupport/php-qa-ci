<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidAmbiguousArrayDocRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidAmbiguousArrayDocRule>
 */
#[CoversClass(ForbidAmbiguousArrayDocRule::class)]
#[Medium]
final class ForbidAmbiguousArrayDocRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/AmbiguousArrayDoc';

    private const string ADVICE = ' leaves the keys unstated: write list<T> for a list or array<K, V> for a map.';

    #[Test]
    public function everyBracketAndSingleArgumentArrayTypeIsReportedOnItsOwnTagLine(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                ['@var type "string[]"' . self::ADVICE, 9],
                ['@var type "array<int>"' . self::ADVICE, 12],
                ['@param $link type "string[]"' . self::ADVICE, 16],
                ['@param $errors type "array<string>"' . self::ADVICE, 17],
                ['@return type "array<array<string>>"' . self::ADVICE, 19],
                ['@return type "non-empty-array<int>"' . self::ADVICE, 26],
                ['@var $values type "int[]"' . self::ADVICE, 34],
            ],
        );
    }

    #[Test]
    public function listsKeyedArraysShapesIterablesAndPlainTypesAreNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidAmbiguousArrayDocRule();
    }
}
