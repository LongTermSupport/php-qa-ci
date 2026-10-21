<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidBuildConstantStrictComparisonRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidBuildConstantStrictComparisonRule>
 */
#[CoversClass(ForbidBuildConstantStrictComparisonRule::class)]
#[Medium]
final class ForbidBuildConstantStrictComparisonRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/BuildConstant';

    private const string ZTS = 'PHP_ZTS';

    private const string DEBUG = 'PHP_DEBUG';

    #[Test]
    public function everyUseWhoseResultDependsOnTheConstantsTypeIsReported(): void
    {
        $zts   = $this->message(self::ZTS);
        $debug = $this->message(self::DEBUG);

        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$zts, 16],
                [$zts, 22],
                [$zts, 23],
                [$debug, 24],
                [$zts, 25],
                [$debug, 31],
                [$zts, 40],
                [$zts, 47],
                [$zts, 54],
                [$zts, 59],
                [$debug, 64],
                [$zts, 69],
                [$debug, 74],
                [$zts, 81],
                [$debug, 86],
                [$zts, 91],
                [$zts, 97],
                [$zts, 102],
                [$debug, 107],
                [$zts, 112],
                [$debug, 117],
                [$zts, 122],
                [$zts, 127],
                [$zts, 132],
                [$zts, 137],
                [$zts, 142],
                [$debug, 154],
                [$zts, 162],
                [$debug, 169],
                [$zts, 174],
                [$debug, 179],
                [$debug, 186],
                [$zts, 187],
                [$zts, 188],
                [$debug, 189],
                [$zts, 190],
                [$zts, 191],
                [$zts, 192],
                [$zts, 204],
                [$debug, 205],
                [$zts, 207],
                [$debug, 210],
                [$zts, 215],
            ],
        );
    }

    #[Test]
    public function aUseThatReadsOnlyTheConstantsTruthOrNumberIsNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidBuildConstantStrictComparisonRule();
    }

    private function message(string $constant): string
    {
        return $constant . ' is read as it is, but it is a bool since PHP 8.4 and an int before (PHPStan types it 0|1 on every version), '
            . 'so this use behaves differently by version. Write (bool) \\' . $constant . '.';
    }
}
