<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ArrowFunctionSpanCollector;
use LTS\PHPQA\PHPStan\Rules\ForbidNumericStringArrayKeyRule;
use LTS\PHPQA\PHPStan\Rules\NumericStringArrayKeyCollector;
use LTS\PHPQA\PHPStan\Rules\NumericStringArrayKeyWritesCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStan\Type\FileTypeMapper;
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
#[CoversClass(NumericStringArrayKeyWritesCollector::class)]
#[CoversClass(ArrowFunctionSpanCollector::class)]
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

    private const string LITERAL = 'The array literal';

    private const string SET = '$set';

    private const string COUNTS_LOCAL = '$counts';

    private const string M = '$m';

    private const string ASSIGNS_BY_NAME = 'assigns it to $this->byName, declared array<string, int>';

    private const string RETURNS = 'returns it as array<string, int>';

    private const string YIELDS = 'yields it as array<string, int>';

    private const string STRING_INT = 'array<string, int>';

    private const string MAP = '$map';

    private const string COPY = '$copy';

    private const string REGISTRY = '$registry';

    private const string ITERATED = 'The result of iterator_to_array()';

    private const string REGISTRY_READ = 'line 107 passes it to takesMap() as array<string, int>';

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
                [$this->message(self::COPY, 'line 50 passes it to accept() as array<string, string>'), 49],
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
                [$this->message('$built', 'line 152 returns it as array<string, string>'), 149],
                [$this->message(self::LITERAL, 'line 158 returns it as array<string, string>'), 158],
                [$this->message(self::LITERAL, 'line 164 passes it to accept() as array<string, string>'), 164],
                [$this->message('$map', 'line 172 returns it as array<string, string>'), 170],
                [$this->message(self::LITERAL, 'line 177 assigns it to $this->reasons, declared array<string, string>'), 177],
                [$this->message(self::LITERAL . '[…]', 'line 183 returns it as array<string, array<string, int>>'), 183],
            ],
        );
    }

    /** Keys made by array_merge(), array_replace(), the functions that build keys from values, renumbering, and an unchecked inline @var. */
    #[Test]
    public function keysMadeWhereNoElementIsWrittenAreReportedWhereTakenAsStringKeyed(): void
    {
        require_once self::FIXTURES . '/ReportableSources.php';

        $this->analyse(
            [self::FIXTURES . '/ReportableSources.php'],
            [
                [$this->message(self::LITERAL, 'line 23 reads its keys with array_merge()'), 23],
                [$this->message(self::LITERAL, 'line 33 reads its keys with array_merge()'), 33],
                [$this->message(self::LITERAL, 'line 45 reads its keys with array_merge()'), 45],
                [$this->message(self::LITERAL, 'line 50 assigns it to $this->tools, declared array<string, object>'), 50],
                [$this->message('$next', 'line 58 returns it as array<string, int>'), 56],
                [$this->message('The result of iterator_to_array()', 'line 63 reads its keys with foreach'), 63],
                [$this->message(self::SET, 'line 72 reads its keys with foreach'), 71],
                [$this->message('The result of array_flip()', 'line 80 assigns it to $this->byName, declared array<string, int>'), 80],
                [$this->message(self::SET, 'line 87 reads its keys with array_keys()'), 86],
                [$this->message('The result of array_combine()', 'line 100 returns it as array<string, int>'), 100],
                [$this->message('The result of array_count_values()', 'line 110 returns it as array<string, int>'), 110],
                [$this->message('The result of array_column()', 'line 120 returns it as array<string, int>'), 120],
                [$this->message(self::SET, 'line 131 reads its keys with array_reverse()'), 128],
                [$this->message(self::COUNTS_LOCAL, 'line 138 assigns it to $this->byName, declared array<string, int>'), 137],
                [$this->message(self::COUNTS_LOCAL, 'line 148 returns it as array<string, int>'), 145],
                [$this->message('$psr4', 'line 154 asserts it array<string, string> in an inline @var over keys PHPStan does not know'), 154],
                [$this->message('$count', 'line 168 reads its keys with array_any()'), 165],
                [$this->message(self::SET, 'line 180 reads its keys with foreach'), 176],
                [$this->message(self::COUNTS_LOCAL, 'line 193 returns it as list<array<string, int>>'), 191],
                [$this->message(self::COUNTS_LOCAL, 'line 202 yields its keys with yield from'), 200],
                [$this->message('$decoded', 'line 213 asserts it array<string, mixed> in an inline @var over keys PHPStan does not know'), 213],
            ],
        );
    }

    /** Keys through `+`, `+=`, `??=`, `??`, `?:`, a ternary, a match arm or a cast, and the readers besides array_merge(). */
    #[Test]
    public function keysReachingADeclarationThroughAnOperatorOrABranchAreReported(): void
    {
        require_once self::FIXTURES . '/ReportableBranches.php';

        $this->analyse(
            [self::FIXTURES . '/ReportableBranches.php'],
            [
                [$this->message(self::LITERAL, $this->at(22, self::ASSIGNS_BY_NAME)), 22],
                [$this->message(self::LITERAL, $this->at(27, self::ASSIGNS_BY_NAME)), 27],
                [$this->message(self::LITERAL, $this->at(32, self::ASSIGNS_BY_NAME)), 32],
                [$this->message(self::LITERAL, 'line 37 assigns it to $this->maybe, declared array<string, int>|null'), 37],
                [$this->message(self::LITERAL, $this->at(42, self::ASSIGNS_BY_NAME)), 42],
                [$this->message(self::LITERAL, $this->at(48, self::RETURNS)), 48],
                [$this->message(self::LITERAL, $this->at(54, self::RETURNS)), 54],
                [$this->message(self::LITERAL, $this->at(63, self::RETURNS)), 63],
                [$this->message(self::M, $this->at(72, self::RETURNS)), 70],
                [$this->message(self::M, $this->at(80, self::RETURNS)), 78],
                [$this->message(self::M, $this->at(89, self::RETURNS)), 87],
                [$this->message(self::LITERAL, 'line 94 passes it to takesMap() as array<string, int>'), 94],
                [$this->message('The result of array_flip()', $this->at(100, self::ASSIGNS_BY_NAME)), 100],
                [$this->message(self::LITERAL, $this->at(106, self::RETURNS)), 106],
                [$this->message(self::M, $this->at(115, self::RETURNS)), 113],
                [$this->message(self::M, 'line 126 reads its keys with foreach'), 123],
                [$this->message(self::M, $this->at(138, self::RETURNS)), 135],
                [$this->message(self::M, 'line 147 reads its keys with array_pad()'), 145],
                [$this->message(self::M, 'line 154 reads its keys with array_multisort()'), 153],
                [$this->message(self::M, 'line 162 reads its keys with json_encode()'), 160],
                [$this->message(self::LITERAL, 'line 167 reads its keys with Safe\json_encode()'), 167],
                [$this->message(self::M, 'line 175 reads its keys with array_is_list()'), 173],
                [$this->message(self::M, 'line 182 reads its keys with array_rand()'), 181],
                [$this->message(self::M, 'line 190 reads its keys with array_diff_ukey()'), 188],
                [$this->message(self::M, $this->at(199, self::RETURNS)), 197],
                [$this->message(self::M, 'line 207 reads its keys with http_build_query()'), 205],
                [$this->message(self::M, 'line 216 returns it as list<array<string, int>>'), 214],
                [$this->message(self::M, 'line 223 reads its keys with Safe\array_walk_recursive()'), 222],
                [$this->message(self::M, $this->at(234, self::RETURNS)), 232],
            ],
        );
    }

    /** An inline @var on a variable the statement binds or one already held, and an iterator held in a variable. */
    #[Test]
    public function anInlineVarOverKeysPhpstanDoesNotKnowIsReportedWhereverTheVariableComesFrom(): void
    {
        require_once self::FIXTURES . '/ReportableAssertions.php';

        $this->analyse(
            [self::FIXTURES . '/ReportableAssertions.php'],
            [
                [$this->message('$package', $this->asserted(26, 'array<string, mixed>')), 26],
                [$this->message('$counts', $this->asserted(37, self::STRING_INT)), 37],
                [$this->message(self::MAP, $this->asserted(46, self::STRING_INT)), 46],
                [$this->message(self::MAP, $this->asserted(54, self::STRING_INT)), 54],
                [$this->message(self::MAP, $this->asserted(61, self::STRING_INT)), 61],
                [$this->message(self::MAP, $this->asserted(69, self::STRING_INT)), 69],
                [$this->message(self::MAP, $this->asserted(77, self::STRING_INT)), 77],
                [$this->message(self::MAP, $this->asserted(87, self::STRING_INT)), 87],
                [$this->message(self::MAP, $this->asserted(96, self::STRING_INT)), 96],
                [$this->message(self::REGISTRY, $this->asserted(102, self::STRING_INT)), 102],
                [$this->message(self::ITERATED, 'line 109 reads its keys with foreach'), 109],
                [$this->message(self::ITERATED, 'line 118 reads its keys with foreach'), 118],
                [$this->message(self::ITERATED, 'line 129 reads its keys with foreach'), 129],
                [$this->message(self::MAP, $this->asserted(142, self::STRING_INT)), 142],
                [$this->message(self::MAP, $this->asserted(155, self::STRING_INT)), 155],
                [$this->message(self::MAP, $this->asserted(172, self::STRING_INT)), 172],
                [$this->message(self::MAP, $this->asserted(192, self::STRING_INT)), 192],
                [$this->message(self::MAP, $this->asserted(209, self::STRING_INT)), 209],
                [$this->message(self::MAP, $this->asserted(225, self::STRING_INT)), 225],
                [$this->message(self::MAP, $this->asserted(233, self::STRING_INT)), 233],
                [$this->message(self::MAP, $this->asserted(241, self::STRING_INT)), 241],
                [$this->message(self::MAP, $this->asserted(249, self::STRING_INT)), 249],
                [$this->message(self::MAP, $this->asserted(257, self::STRING_INT)), 257],
                [$this->message(self::MAP, $this->asserted(266, self::STRING_INT)), 266],
                [$this->message(self::MAP, $this->asserted(285, self::STRING_INT)), 285],
                [$this->message(self::MAP, $this->asserted(296, self::STRING_INT)), 296],
            ],
        );
    }

    /** An inline @var restating keys PHPStan knew, and an iterator held in a variable that keys by path name. */
    #[Test]
    public function anInlineVarRestatingKnownKeysOrAnIteratorKeyedByPathNameIsNotReported(): void
    {
        require_once self::FIXTURES . '/NotReportableAssertions.php';

        $this->analyse([self::FIXTURES . '/NotReportableAssertions.php'], []);
    }

    /** A variable written at the top level of a script is a global to a function that imports it. */
    #[Test]
    public function aScriptLevelVariableIsAGlobalToAFunctionThatImportsIt(): void
    {
        require_once self::FIXTURES . '/ReportableScriptLevel.php';

        $this->analyse(
            [self::FIXTURES . '/ReportableScriptLevel.php'],
            [[$this->message(self::REGISTRY, 'line 21 returns it as array<string, int>'), 14]],
        );
    }

    /** A key PHP made, which is left alone, ahead of an inline @var in a body that may write any variable unseen. */
    #[Test]
    public function aFindingAfterAQuietRecordInTheSameFileIsStillReported(): void
    {
        require_once self::FIXTURES . '/ReportableAfterQuiet.php';

        $this->analyse(
            [self::FIXTURES . '/ReportableAfterQuiet.php'],
            [
                [$this->message(self::MAP, $this->asserted(38, self::STRING_INT)), 38],
                [$this->message(self::MAP, $this->asserted(47, self::STRING_INT)), 47],
                [$this->message(self::COPY, $this->at(64, self::RETURNS)), 59],
                [$this->message(self::COPY, $this->at(64, self::RETURNS)), 62],
            ],
        );
    }

    /** By reference, static, global, $GLOBALS, yield, an element of another array, ArrayIterator and ArrayObject. */
    #[Test]
    public function anArrayHandedOnBeforeItIsTakenAsStringKeyedIsReported(): void
    {
        require_once self::FIXTURES . '/ReportableHandedOn.php';

        $this->analyse(
            [self::FIXTURES . '/ReportableHandedOn.php'],
            [
                [$this->message('$alias', $this->at(27, self::RETURNS)), 25],
                [$this->message(self::MAP, $this->at(37, self::RETURNS)), 34],
                [$this->message('$alias', 'line 42 makes it a reference to $this->byName, declared array<string, int>'), 43],
                [$this->message('$group', 'line 59 returns it as array<string, array<string, int>>'), 54],
                [$this->message('$groups[…]', 'line 71 reads its keys with foreach'), 67],
                [$this->message('$out', 'line 78 hands it back by reference as array<string, int>'), 80],
                [$this->message('$cache', $this->at(89, self::RETURNS)), 87],
                [$this->message(self::REGISTRY, self::REGISTRY_READ), 95],
                [$this->message("\$GLOBALS['registry']", self::REGISTRY_READ), 100],
                [$this->message(self::MAP, $this->at(117, self::YIELDS)), 115],
                [$this->message(self::LITERAL, $this->at(118, self::YIELDS)), 118],
                [$this->message(self::MAP, $this->at(129, self::RETURNS)), 125],
                [$this->message('$map[…]', 'line 138 returns it as array<string, array<string, int>>'), 136],
                [$this->message(self::LITERAL, 'line 143 assigns it to $this->nested[…], declared array<string, int>'), 143],
                [$this->message('$inner', 'line 154 returns it as array<string, array<string, int>>'), 150],
                [$this->message('$map[…]', $this->at(164, self::RETURNS)), 161],
                [$this->message(self::MAP, 'line 173 returns it as Iterator<string, int>'), 171],
                [$this->message(self::LITERAL, 'line 178 passes it to takesObject() as ArrayObject<string, int>'), 178],
            ],
        );
    }

    #[Test]
    public function anArrayHandedOnWhereNothingTakesItAsStringKeyedIsNotReported(): void
    {
        require_once self::FIXTURES . '/NotReportableHandedOn.php';

        $this->analyse([self::FIXTURES . '/NotReportableHandedOn.php'], []);
    }

    #[Test]
    public function keysThroughAnOperatorOrABranchThatCannotBeAllDigitsOrAreReadAsStringsAreNotReported(): void
    {
        require_once self::FIXTURES . '/NotReportableBranches.php';

        $this->analyse([self::FIXTURES . '/NotReportableBranches.php'], []);
    }

    #[Test]
    public function keysMadeWhereNoElementIsWrittenThatCannotBeAllDigitsOrAreNeverReadAreNotReported(): void
    {
        require_once self::FIXTURES . '/NotReportableSources.php';

        $this->analyse([self::FIXTURES . '/NotReportableSources.php'], []);
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
        return [new NumericStringArrayKeyCollector(self::createReflectionProvider(), self::getContainer()->getByType(FileTypeMapper::class)), new NumericStringArrayKeyWritesCollector(), new ArrowFunctionSpanCollector()];
    }

    private function asserted(int $line, string $type): string
    {
        return 'line ' . $line . ' asserts it ' . $type . ' in an inline @var over keys PHPStan does not know';
    }

    private function at(int $line, string $text): string
    {
        return 'line ' . $line . ' ' . $text;
    }

    private function message(string $target, string $reason): string
    {
        return $target . ' is keyed by a string that may be all digits, which PHP stores as an int key, but ' . $reason . '. '
            . 'Declare its keys array-key and cast a key to string where one is read, or key it by a value that cannot be all digits. '
            . 'See docs/phpstan-rules/forbid-numeric-string-array-key.md.';
    }
}
