<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidDeprecatedPhpunitMethodRule;
use LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit\FakeAssert;
use LTS\PHPQA\Tests\Assets\PHPStan\DeprecatedPhpunit\FakeTestCase;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Integration test: analyses real fixture files so scope-based type resolution
 * (does this call land on a PHPUnit type?) is exercised end-to-end. Configured
 * against a self-contained fake PHPUnit hierarchy ({@see FakeTestCase} /
 * {@see FakeAssert}) so the assertions do not depend on which methods the
 * installed PHPUnit deprecates.
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidDeprecatedPhpunitMethodRule>
 */
#[CoversClass(ForbidDeprecatedPhpunitMethodRule::class)]
#[Medium]
final class ForbidDeprecatedPhpunitMethodRuleTest extends RuleTestCase
{
    private const string DEPRECATED_MESSAGE_PREFIX = 'Method "';

    public function testItFlagsDeprecatedInstanceAndStaticCallsOnPhpunitTypesOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/../../../assets/PHPStan/DeprecatedPhpunit/ConsumerTest.php'],
            [
                [self::DEPRECATED_MESSAGE_PREFIX . 'legacyExpect()" is deprecated in the installed PHPUnit; '
                    . 'replace it with a non-deprecated equivalent. For expectExceptionMessage() prefer '
                    . 'expectExceptionObject(), otherwise expectExceptionMessageIs() / '
                    . 'expectExceptionMessageIsOrContains() / expectExceptionMessageMatches().', 18],
                [self::DEPRECATED_MESSAGE_PREFIX . 'legacyAssert()" is deprecated in the installed PHPUnit; '
                    . 'replace it with a non-deprecated equivalent. For expectExceptionMessage() prefer '
                    . 'expectExceptionObject(), otherwise expectExceptionMessageIs() / '
                    . 'expectExceptionMessageIsOrContains() / expectExceptionMessageMatches().', 20],
                [self::DEPRECATED_MESSAGE_PREFIX . 'legacyStaticAssert()" is deprecated in the installed PHPUnit; '
                    . 'replace it with a non-deprecated equivalent. For expectExceptionMessage() prefer '
                    . 'expectExceptionObject(), otherwise expectExceptionMessageIs() / '
                    . 'expectExceptionMessageIsOrContains() / expectExceptionMessageMatches().', 22],
            ],
        );
    }

    public function testItDoesNotFlagANameCollidingMethodOnANonPhpunitType(): void
    {
        $this->analyse(
            [__DIR__ . '/../../../assets/PHPStan/DeprecatedPhpunit/NotATest.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidDeprecatedPhpunitMethodRule(
            $this->createReflectionProvider(),
            [FakeTestCase::class, FakeAssert::class],
        );
    }
}
