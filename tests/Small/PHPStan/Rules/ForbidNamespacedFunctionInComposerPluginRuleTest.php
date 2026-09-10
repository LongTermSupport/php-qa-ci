<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidNamespacedFunctionInComposerPluginRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidNamespacedFunctionInComposerPluginRule>
 */
#[CoversClass(ForbidNamespacedFunctionInComposerPluginRule::class)]
#[Medium]
final class ForbidNamespacedFunctionInComposerPluginRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan';

    private const string ADVICE = '(): a dependency\'s "files" autoload is not registered when a plugin activates part-way through an install. Use a global function or Composer\'s own API.';

    #[Test]
    public function everyNamespacedFunctionCallInAPluginIsReportedWhetherQualifiedOrImported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/ComposerPlugin/Reportable.php'],
            [
                ['Composer plugin calls namespaced function Safe\exec' . self::ADVICE, 14],
                ['Composer plugin calls namespaced function Safe\json_decode' . self::ADVICE, 15],
                ['Composer plugin calls namespaced function Safe\file_get_contents' . self::ADVICE, 16],
            ],
        );
    }

    #[Test]
    public function globalFunctionsAndStaticCallsInAPluginAreNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/ComposerPlugin/NotReportable.php'], []);
    }

    #[Test]
    public function aClassOutsideTheComposerPluginNamespaceIsNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/ComposerPluginOutside/NotAPlugin.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidNamespacedFunctionInComposerPluginRule($this->createReflectionProvider());
    }
}
