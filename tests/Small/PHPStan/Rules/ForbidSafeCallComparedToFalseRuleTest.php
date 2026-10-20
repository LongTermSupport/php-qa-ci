<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidSafeCallComparedToFalseRule;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidSafeCallComparedToFalseRule>
 */
#[CoversClass(ForbidSafeCallComparedToFalseRule::class)]
#[Medium]
final class ForbidSafeCallComparedToFalseRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/SafeCallComparedToFalse';

    private const string REALPATH = 'Safe\realpath';

    #[Test]
    public function aSafeCallComparedWithFalseOrTestedAsAConditionIsReported(): void
    {
        $comparison = $this->comparisonMessage(self::REALPATH);
        $condition  = $this->conditionMessage(self::REALPATH);

        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$comparison, 17],
                [$comparison, 26],
                [$comparison, 31],
                [$condition, 36],
                [$condition, 36],
                [$comparison, 41],
                [$condition, 50],
                [$condition, 61],
                [$condition, 70],
                [$condition, 74],
                [$condition, 79],
                [$condition, 81],
                [$this->conditionMessage('Safe\fopen'), 86],
                [$condition, 93],
                [$condition, 95],
                [$comparison, 102],
                [$condition, 106],
                [$comparison, 115],
                [$comparison, 119],
                [$condition, 123],
                [$comparison, 128],
                [$comparison, 128],
                [$condition, 133],
                [$condition, 137],
                [$condition, 142],
                [$comparison, 147],
                [$condition, 151],
                [$condition, 155],
            ],
        );
    }

    #[Test]
    public function nativeCallsOtherFunctionsAndResultsThatCanBeFalseOrFalsyAreNotReported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/NotReportable.php', self::FIXTURES . '/Lookup/find.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidSafeCallComparedToFalseRule(self::getContainer()->getByType(ReflectionProvider::class));
    }

    private function comparisonMessage(string $function): string
    {
        return $function . '() never returns false: it throws a Safe\Exceptions exception on failure, so this '
            . 'comparison with false can never hold and the failure it was written for throws instead. Catch the '
            . 'Safe\Exceptions exception, or use an API that reports failure without throwing. '
            . 'See docs/phpstan-rules/forbid-safe-call-compared-to-false.md';
    }

    private function conditionMessage(string $function): string
    {
        return $function . '() never returns false: it throws a Safe\Exceptions exception on failure, so this '
            . 'condition holds whenever the call returns and never sees the failure it was written for. Catch the '
            . 'Safe\Exceptions exception, or use an API that reports failure without throwing. '
            . 'See docs/phpstan-rules/forbid-safe-call-compared-to-false.md';
    }
}
