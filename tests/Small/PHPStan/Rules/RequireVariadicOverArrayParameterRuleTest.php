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
    public function listNonEmptyListAndSingleArgArrayLastParametersAreFlaggedOnMethodsAndFunctions(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                ['Parameter $items of listParam() is a list<string> in a docblock; declare it "string ...$items" so the engine checks it.', 14],
                ['Parameter $items of nonEmptyListParam() is a non-empty-list<int> in a docblock; declare it "int ...$items" so the engine checks it.', 20],
                ['Parameter $items of singleArgArrayParam() is a array<Item> in a docblock; declare it "Item ...$items" so the engine checks it.', 26],
                ['Parameter $items of multipleParamsLastIsList() is a list<string> in a docblock; declare it "string ...$items" so the engine checks it.', 36],
                ['Parameter $parts of joinAll() is a list<string> in a docblock; declare it "string ...$parts" so the engine checks it.', 43],
            ],
        );
    }

    #[Test]
    public function noneOfThePromotedByRefVariadicMapShapeMissingOrDataProviderCasesAreFlagged(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
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
