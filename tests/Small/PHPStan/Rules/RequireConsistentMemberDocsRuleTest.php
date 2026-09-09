<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireConsistentMemberDocsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<RequireConsistentMemberDocsRule>
 */
#[CoversClass(RequireConsistentMemberDocsRule::class)]
#[Medium]
final class RequireConsistentMemberDocsRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/ConsistentMemberDocs';

    #[Test]
    public function mixedPropertiesAreFlaggedCountingPromotedAndDeclaredTogetherAndIgnoringTagOnlyDocblocks(): void
    {
        $this->analyse(
            [self::FIXTURES . '/MixedProperties.php'],
            [['1 of 4 properties are documented; document all of them or none (delete comments that restate the name). Undocumented: $tagsOnly, $cwd, $streamOutput.', 10]],
        );
    }

    #[Test]
    public function mixedEnumCasesAreFlaggedAsConstants(): void
    {
        $this->analyse(
            [self::FIXTURES . '/MixedConstants.php'],
            [['1 of 3 constants are documented; document all of them or none (delete comments that restate the name). Undocumented: Passed, Skipped.', 11]],
        );
    }

    #[Test]
    public function allOrNonePerKindIsAcceptedAndMethodsAreNotChecked(): void
    {
        $this->analyse([self::FIXTURES . '/Consistent.php'], []);
    }

    protected function getRule(): Rule
    {
        return new RequireConsistentMemberDocsRule();
    }
}
