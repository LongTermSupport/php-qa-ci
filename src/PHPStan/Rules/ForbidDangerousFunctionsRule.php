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
 * Bans dangerous PHP functions that enable code/command injection.
 *
 * Functions like exec(), shell_exec(), eval(), unserialize() are OWASP A03 risks.
 * Use safe alternatives: shell commands via Symfony Process, serialization via JSON.
 *
 * See: docs/phpstan-rules/forbid-dangerous-functions.md for fix documentation.
 *
 * @implements Rule<FuncCall>
 */
final class ForbidDangerousFunctionsRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.dangerousFunctions';

    /**
     * @var list<string>
     */
    private const array BANNED_FUNCTIONS = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
        'eval',
        'unserialize',
        'extract',
        'parse_str',
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

        // parse_str is only dangerous without the second argument (output variable)
        if ('parse_str' === $functionName && \count($node->args) >= 2) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    'Dangerous function %s() is banned (OWASP A03 Injection). '
                    . 'See docs/phpstan-rules/forbid-dangerous-functions.md for safe alternatives.',
                    $functionName,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
