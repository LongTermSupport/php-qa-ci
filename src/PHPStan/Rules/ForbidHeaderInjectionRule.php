<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans raw header(), setcookie(), and setrawcookie() function calls.
 *
 * Direct header manipulation bypasses Symfony's response layer and enables
 * HTTP response splitting (OWASP A03 Injection). Use Symfony Response methods instead.
 *
 * See: docs/phpstan-rules/forbid-header-injection.md for fix documentation.
 *
 * @implements Rule<FuncCall>
 */
final class ForbidHeaderInjectionRule implements Rule
{
    /**
     * @var list<string>
     */
    private const array BANNED_FUNCTIONS = [
        'header',
        'setcookie',
        'setrawcookie',
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();

        if (!\in_array($functionName, self::BANNED_FUNCTIONS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Raw %s() call is banned (OWASP A03 Injection). '
                    . 'Use Symfony Response/Cookie methods instead. '
                    . 'See docs/phpstan-rules/forbid-header-injection.md for safe alternatives.',
                    $functionName,
                ),
            )->identifier('counselbook.headerInjection')->build(),
        ];
    }
}
