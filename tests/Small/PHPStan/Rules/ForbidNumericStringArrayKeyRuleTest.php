<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidNumericStringArrayKeyRule;
use LTS\PHPQA\PHPStan\Rules\NumericStringArrayKeyCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidNumericStringArrayKeyRule>
 */
#[CoversClass(ForbidNumericStringArrayKeyRule::class)]
#[CoversClass(NumericStringArrayKeyCollector::class)]
#[Medium]
final class ForbidNumericStringArrayKeyRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/NumericStringArrayKey';

    private const string REASONS = '$this->reasons';

    private const string COUNTS = 'self::$counts';

    private const string DECLARED_REASONS = 'it is declared array<string, string>';

    private const string DECLARED_COUNTS = 'it is declared array<string, int>';

    private const string NESTED_DECLARED = 'it is declared array<string, array<string, int>>';

    private const string SEEN = '$seen';

    private const string BY_YEAR = '$byYear';

    private const string BY_YEAR_PASSED = 'line 104 passes it to accept() as array<string, string>';

    /** The fixture is loaded first so its classes are known to the reflection provider, as autoloaded classes are. */
    #[Test]
    public function aStringKeyThatMayBeAllDigitsIsReportedWhereItsArrayIsTakenAsStringKeyed(): void
    {
        require_once self::FIXTURES . '/Reportable.php';

        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$this->message(self::REASONS, self::DECLARED_REASONS), 25],
                [$this->message('$this->nested', self::NESTED_DECLARED), 30],
                [$this->message('$this->nested[…]', self::NESTED_DECLARED), 30],
                [$this->message(self::COUNTS, self::DECLARED_COUNTS), 35],
                [$this->message(self::REASONS, self::DECLARED_REASONS), 40],
                [$this->message(self::REASONS, self::DECLARED_REASONS), 41],
                [$this->message(self::COUNTS, self::DECLARED_COUNTS), 42],
                [$this->message('$copy', 'line 50 passes it to accept() as array<string, string>'), 49],
                [$this->message('$map', 'line 52 passes it to accept() as array<string, string>'), 51],
                [$this->message('$counts', 'line 63 returns it as array<string, int>'), 60],
                [$this->message('$byPath', 'line 71 passes it to new Holder() as array<string, int>'), 69],
                [$this->message('$reasons', 'line 78 assigns it to $this->reasons, declared array<string, string>'), 77],
                [$this->message(self::SEEN, 'line 85 reads its keys with foreach'), 84],
                [$this->message(self::SEEN, 'line 96 reads its keys with array_keys()'), 94],
                [$this->message(self::BY_YEAR, self::BY_YEAR_PASSED), 102],
                [$this->message(self::BY_YEAR, self::BY_YEAR_PASSED), 103],
                [$this->message('$groups', 'line 111 passes it to acceptGroups() as array<string, list<string>>'), 110],
                [$this->message('$trimmed', 'line 127 returns it as array<string, int>'), 124],
                [$this->message('$copied', 'line 142 returns it as array<string, int>'), 139],
            ],
        );
    }

    #[Test]
    public function aKeyThatCannotBeAllDigitsOrAnArrayHonestAboutItsKeysIsNotReported(): void
    {
        require_once self::FIXTURES . '/NotReportable.php';

        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidNumericStringArrayKeyRule();
    }

    protected function getCollectors(): array
    {
        return [new NumericStringArrayKeyCollector(self::createReflectionProvider())];
    }

    private function message(string $target, string $reason): string
    {
        return $target . ' is keyed by a string that may be all digits, which PHP stores as an int key, but ' . $reason . '. '
            . 'Declare its keys array-key and cast a key to string where one is read, or key it by a value that cannot be all digits. '
            . 'See docs/phpstan-rules/forbid-numeric-string-array-key.md.';
    }
}
