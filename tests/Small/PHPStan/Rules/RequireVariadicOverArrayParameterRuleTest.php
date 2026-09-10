<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireVariadicOverArrayParameterRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<RequireVariadicOverArrayParameterRule>
 */
#[CoversClass(RequireVariadicOverArrayParameterRule::class)]
#[Medium]
final class RequireVariadicOverArrayParameterRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/VariadicOverArrayParameter';

    #[Test]
    public function onlyListAndNonEmptyListParametersAreFlaggedOnMethodsAndFunctions(): void
    {
        /*
         * singleArgArrayParam() carries `@param array<Item>` and is deliberately
         * absent: PHPStan expands array<T> to array<mixed, mixed>, so the single
         * argument constrains the value type and says nothing about the keys. It
         * may be a map, and a variadic would silently discard them.
         */
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                ['Parameter $items of listParam() is a list<string> in a docblock; declare it "string ...$items" so the engine checks it.', 14],
                ['Parameter $items of nonEmptyListParam() is a non-empty-list<int> in a docblock; declare it "int ...$items" so the engine checks it.', 20],
                ['Parameter $items of multipleParamsLastIsList() is a list<string> in a docblock; declare it "string ...$items" so the engine checks it.', 36],
                ['Parameter $classes of classStringParam() is a list<class-string> in a docblock; declare it "string ...$classes" so the engine checks it.', 47],
                ['Parameter $offsets of boundedIntParam() is a list<int<0, max>> in a docblock; declare it "int ...$offsets" so the engine checks it.', 53],
                ['Parameter $sizes of positiveIntParam() is a list<positive-int> in a docblock; declare it "int ...$sizes" so the engine checks it.', 59],
                ['Parameter $debts of negativeIntParam() is a list<negative-int> in a docblock; declare it "int ...$debts" so the engine checks it.', 65],
                ['Parameter $values of spacedGenericParam() is a list<string> in a docblock; declare it "string ...$values" so the engine checks it.', 71],
                ['Parameter $itemNames of similarlyNamedParams() is a list<string> in a docblock; move it to last and declare it "string ...$itemNames" so the engine checks it.', 83],
                ['Parameter $parts of joinAll() is a list<string> in a docblock; declare it "string ...$parts" so the engine checks it.', 90],
                ['Parameter $parts of joinWithSuffix() is a list<string> in a docblock; move it to last and declare it "string ...$parts" so the engine checks it.', 101],
            ],
        );
    }

    #[Test]
    public function noneOfThePromotedByRefVariadicMapShapeMissingOrDataProviderCasesAreFlagged(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    #[Test]
    public function aConstructorIsExemptEvenWhenItsListParameterIsNotPromoted(): void
    {
        $this->analyse([self::FIXTURES . '/ConstructorTakesAList.php'], []);
    }

    #[Test]
    public function onlyTheRootInterfaceOrParentDeclarationIsFlaggedNeverTheImplementationOrOverride(): void
    {
        $this->analyse(
            [
                self::FIXTURES . '/Repository.php',
                self::FIXTURES . '/ArrayRepository.php',
                self::FIXTURES . '/BaseCollector.php',
                self::FIXTURES . '/ChildCollector.php',
            ],
            [
                ['Parameter $ids of findAll() is a list<string> in a docblock; declare it "string ...$ids" so the engine checks it.', 14],
                ['Parameter $items of collect() is a list<string> in a docblock; declare it "string ...$items" so the engine checks it.', 14],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new RequireVariadicOverArrayParameterRule();
    }
}
