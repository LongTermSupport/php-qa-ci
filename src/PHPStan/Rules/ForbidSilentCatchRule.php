<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Detects catch blocks that silently swallow exceptions.
 *
 * A catch block that neither re-throws, logs, nor uses the caught exception
 * silently hides errors. This is stricter than ForbidEmptyCatchBlockRule:
 * it also flags blocks that have statements but ignore the exception entirely.
 *
 * WRONG:
 *   try { ... } catch (Throwable) { return null; }
 *
 * RIGHT:
 *   try { ... } catch (Throwable $e) { $this->logger->error('...', ['exception' => $e]); }
 *
 * @implements Rule<Catch_>
 */
final readonly class ForbidSilentCatchRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.silentCatch';

    /** @var list<string> */
    private const array LOGGING_METHODS = [
        'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log',
    ];

    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->nodeFinder = new NodeFinder();
    }

    public function getNodeType(): string
    {
        return Catch_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (null === $node->var) {
            if ($this->blockContainsThrow(...$node->stmts)) {
                return [];
            }

            return [$this->buildError()];
        }

        if (!\is_string($node->var->name)) {
            return [];
        }

        $varName = $node->var->name;

        if ($this->variableIsUsedInBlock($varName, ...$node->stmts)) {
            return [];
        }

        if ($this->blockContainsThrow(...$node->stmts)) {
            return [];
        }

        if ($this->blockContainsLogging(...$node->stmts)) {
            return [];
        }

        return [$this->buildError()];
    }

    private function blockContainsThrow(Stmt ...$stmts): bool
    {
        return $this->nodeFinder->findFirst(
            $stmts,
            static fn (Node $found): bool => $found instanceof Throw_,
        ) instanceof Node;
    }

    private function variableIsUsedInBlock(string $varName, Stmt ...$stmts): bool
    {
        return $this->nodeFinder->findFirst(
            $stmts,
            static fn (Node $found): bool => $found instanceof Variable && $found->name === $varName,
        ) instanceof Node;
    }

    private function blockContainsLogging(Stmt ...$stmts): bool
    {
        return $this->nodeFinder->findFirst(
            $stmts,
            static fn (Node $found): bool => $found instanceof MethodCall
                && $found->name instanceof Identifier
                && \in_array($found->name->name, self::LOGGING_METHODS, true),
        ) instanceof Node;
    }

    private function buildError(): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Catch block swallows exception silently. '
            . 'Either re-throw the exception, log it, or use the caught exception variable. '
            . 'Silent catch blocks hide bugs and make debugging impossible.',
        )->identifier(self::IDENTIFIER)->build();
    }
}
