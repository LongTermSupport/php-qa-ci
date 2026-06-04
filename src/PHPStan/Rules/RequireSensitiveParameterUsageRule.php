<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Asserts that the native #[\SensitiveParameter] attribute is used at least once
 * across the whole analysed codebase.
 *
 * THE PROBLEM THIS SOLVES:
 * ========================
 * RequireSensitiveParameterAttributeRule enforces the attribute on parameters
 * whose NAME looks like a credential. That heuristic cannot catch a credential
 * carried under a non-obvious name (e.g. `$token`, `$apiKey`, `$dsn`). This rule
 * is a coverage backstop: it fails the build if the attribute appears NOWHERE,
 * which almost always means a real sensitive parameter has been left unprotected.
 *
 * HOW IT WORKS:
 * =============
 * SensitiveParameterAttributeCollector records every #[\SensitiveParameter]
 * occurrence during analysis. This rule runs once, at the end, on the synthetic
 * PHPStan\Node\CollectedDataNode and errors when the collected total is zero.
 *
 * ESCAPE HATCH:
 * =============
 * A handful of projects genuinely never handle a password, token or secret. For
 * those, set the requireAtLeastOneUsage constructor argument to false (wired from
 * a neon parameter) and the rule becomes a no-op. Most projects should instead
 * add #[\SensitiveParameter] to the relevant parameter somewhere in their code.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class RequireSensitiveParameterUsageRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.requireSensitiveParameterUsage';

    public function __construct(private bool $requireAtLeastOneUsage = true)
    {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->requireAtLeastOneUsage) {
            return [];
        }

        $collected = $node->get(SensitiveParameterAttributeCollector::class);

        $total = 0;
        foreach ($collected as $occurrences) {
            $total += \count($occurrences);
        }

        if ($total > 0) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'No #[\SensitiveParameter] attribute was found anywhere in the analysed codebase. '
                . 'Most projects handle a password, token or secret somewhere and should mark that '
                . 'parameter with #[\SensitiveParameter] so its value is redacted from stack traces. '
                . 'If this project genuinely never handles sensitive parameters, opt out by setting '
                . 'the requireAtLeastOneUsage parameter of RequireSensitiveParameterUsageRule to false '
                . 'in your phpstan.neon.',
            )
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }
}
