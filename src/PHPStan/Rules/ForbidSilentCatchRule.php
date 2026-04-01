<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Throw_;
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
final class ForbidSilentCatchRule implements Rule
{
    /** @var list<string> */
    private const array LOGGING_METHODS = [
        'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log',
    ];

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
            if ($this->blockContainsThrow($node->stmts)) {
                return [];
            }

            return [$this->buildError()];
        }

        if (!$node->var instanceof Variable || !\is_string($node->var->name)) {
            return [];
        }

        $varName = $node->var->name;

        if ($this->variableIsUsedInBlock($node->stmts, $varName)) {
            return [];
        }

        if ($this->blockContainsThrow($node->stmts)) {
            return [];
        }

        if ($this->blockContainsLogging($node->stmts)) {
            return [];
        }

        return [$this->buildError()];
    }

    /**
     * @param list<Node\Stmt>|array<int, Node\Stmt> $stmts
     */
    private function blockContainsThrow(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Throw_) {
                return true;
            }

            foreach ($stmt->getSubNodeNames() as $subNodeName) {
                $subNode = $stmt->{$subNodeName};
                if (\is_array($subNode) && $this->blockContainsThrow($subNode)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<Node\Stmt>|array<int, Node\Stmt> $stmts
     */
    private function variableIsUsedInBlock(array $stmts, string $varName): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->nodeContainsVariable($stmt, $varName)) {
                return true;
            }
        }

        return false;
    }

    private function nodeContainsVariable(Node $node, string $varName): bool
    {
        if ($node instanceof Variable && $node->name === $varName) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node && $this->nodeContainsVariable($subNode, $varName)) {
                return true;
            }

            if (\is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node && $this->nodeContainsVariable($item, $varName)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param list<Node\Stmt>|array<int, Node\Stmt> $stmts
     */
    private function blockContainsLogging(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->nodeContainsLoggingCall($stmt)) {
                return true;
            }
        }

        return false;
    }

    private function nodeContainsLoggingCall(Node $node): bool
    {
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            if (\in_array($node->name->name, self::LOGGING_METHODS, true)) {
                return true;
            }
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node && $this->nodeContainsLoggingCall($subNode)) {
                return true;
            }

            if (\is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node && $this->nodeContainsLoggingCall($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function buildError(): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            'Catch block swallows exception silently. '
            . 'Either re-throw the exception, log it, or use the caught exception variable. '
            . 'Silent catch blocks hide bugs and make debugging impossible.',
        )->identifier('phpqaci.silentCatch')->build();
    }
}
