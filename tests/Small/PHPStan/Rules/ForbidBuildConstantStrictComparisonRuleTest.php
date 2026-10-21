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
        $zts   = self::message(self::ZTS);
        $debug = self::message(self::DEBUG);

        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$zts, 13],
                [$zts, 19],
                [$zts, 20],
                [$debug, 21],
                [$zts, 22],
                [$debug, 28],
                [$zts, 37],
                [$zts, 44],
                [$zts, 51],
                [$zts, 56],
                [$debug, 61],
                [$zts, 66],
                [$debug, 71],
                [$zts, 78],
                [$debug, 83],
                [$zts, 88],
                [$zts, 94],
                [$zts, 99],
                [$debug, 104],
                [$zts, 109],
                [$debug, 114],
                [$zts, 119],
                [$zts, 124],
                [$zts, 129],
                [$zts, 134],
                [$zts, 139],
                [$debug, 151],
                [$zts, 159],
                [$debug, 166],
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

    private static function message(string $constant): string
    {
        return $constant . ' is read as it is, but it is a bool since PHP 8.4 and an int before (PHPStan types it 0|1 on every version), '
            . 'so this use behaves differently by version. Write (bool) \\' . $constant . '.';
    }
}
