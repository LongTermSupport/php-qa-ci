<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Empty_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans the empty() language construct.
 *
 * empty() silently accepts undefined variables and performs loose type coercion:
 * empty(0), empty(''), empty('0'), empty([]), empty(null) all return true.
 * This hides type errors and makes code unpredictable.
 *
 * WRONG:
 *   if (empty($array)) { ... }
 *
 * RIGHT:
 *   if ([] === $array) { ... }
 *   if ('' === $string) { ... }
 *   if (null === $value) { ... }
 *
 * @implements Rule<Empty_>
 */
final readonly class ForbidEmptyLanguageConstructRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.emptyLanguageConstruct';

    public function getNodeType(): string
    {
        return Empty_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message(
                'empty() is banned. Use strict comparison instead: '
                . '"[] === $var" for arrays, '
                . '"$var === \'\'" for strings, '
                . '"$var === null" for nullable. '
                . 'empty() hides type errors and silently accepts undefined variables.',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
