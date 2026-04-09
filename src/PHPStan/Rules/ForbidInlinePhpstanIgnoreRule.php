<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans inline PHPStan suppression annotations in source code.
 *
 * Inline suppression annotations hide type errors instead of fixing them.
 * Fix the underlying type issue using type guards, array shapes, or Safe functions.
 *
 * If a suppression is truly irreducible, it must be managed in phpstan.neon
 * with a specific identifier and path instead of hidden inline.
 *
 * Skips test files and qaConfig files.
 *
 * @implements Rule<Stmt>
 */
final class ForbidInlinePhpstanIgnoreRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.inlinePhpstanIgnore';

    /** Matches the PHPStan inline suppression annotation pattern */
    private const string PATTERN = '/@phpstan\x2dignore/';

    public function getNodeType(): string
    {
        return Stmt::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isTestContext($scope)) {
            return [];
        }

        $namespace = $scope->getNamespace();
        if (null !== $namespace && str_starts_with($namespace, 'QaConfig')) {
            return [];
        }

        $comments = $node->getComments();
        if ([] === $comments) {
            return [];
        }

        $errors = [];

        foreach ($comments as $comment) {
            if (!$this->containsSuppressionAnnotation($comment)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(
                'Inline PHPStan suppression annotations are forbidden. '
                . 'Fix the underlying type issue instead '
                . '(use type guards, array shapes, \Safe\ functions, etc.). '
                . 'If truly irreducible, manage via ignoreErrors in phpstan.neon '
                . 'with a specific identifier and path.',
            )->identifier(self::IDENTIFIER)->line($comment->getStartLine())->build();
        }

        return $errors;
    }

    private function containsSuppressionAnnotation(Comment $comment): bool
    {
        return \Safe\preg_match(self::PATTERN, $comment->getText()) > 0;
    }

    private function isTestContext(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();
        if (null !== $namespace && str_contains($namespace, 'Tests')) {
            return true;
        }

        return str_contains($scope->getFile(), '/tests/');
    }
}
