<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans the debug dump functions when they print. Left in place they leak
 * internal state into whatever the output happens to be — a web response, a
 * JSON body, a log — and they are almost always a debugging session someone
 * forgot to remove.
 *
 * Two of them have a legitimate non-printing form: `print_r($x, true)` and
 * `var_export($x, true)` return a string instead of echoing it, which is a
 * reasonable way to render a value for a message. Those are not reported, and
 * that exemption is why this is a rule rather than a grep.
 *
 * The list is lifted from spaze/phpstan-disallowed-calls (MIT, Copyright (c)
 * 2018 Michal Špaček), which bans these in its dangerous-calls bundle with the
 * same second-argument exemption. Carried here rather than importing the
 * engine, so one rule owns the convention and the two cannot drift.
 *
 * See: docs/phpstan-rules/forbid-debug-output-functions.md.
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidDebugOutputFunctionsRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.debugOutputFunction';

    /**
     * Function name to whether a truthy second argument makes it return a
     * string rather than print, which is the legitimate use.
     *
     * @var array<string, bool>
     */
    private const array BANNED_FUNCTIONS = [
        'var_dump'   => false,
        'print_r'    => true,
        'var_export' => true,
    ];

    private const int RETURN_MODE_ARG = 1;

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
        if (!\array_key_exists($functionName, self::BANNED_FUNCTIONS)) {
            return [];
        }

        if (self::BANNED_FUNCTIONS[$functionName] && $this->returnsAString($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    '%s() prints internal state; remove it or log through the application logger. '
                    . 'See docs/phpstan-rules/forbid-debug-output-functions.md.',
                    $functionName,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function returnsAString(FuncCall $node): bool
    {
        $arg = $node->args[self::RETURN_MODE_ARG] ?? null;
        if (!$arg instanceof Node\Arg) {
            return false;
        }

        return $arg->value instanceof ConstFetch && 'true' === $arg->value->name->toLowerString();
    }
}
