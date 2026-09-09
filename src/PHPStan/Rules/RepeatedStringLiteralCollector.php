<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use SplObjectStorage;

/**
 * Collects, for one class body, the lines on which each counted string
 * literal occurs. The exclusions are ForbidRepeatedStringLiteralRule's.
 *
 * @internal
 */
final class RepeatedStringLiteralCollector extends NodeVisitorAbstract
{
    /** @var array<string, list<int>> */
    private array $occurrences = [];

    /** @var SplObjectStorage<String_, null> */
    private SplObjectStorage $arrayKeys;

    public function __construct(private readonly ClassLike $root)
    {
        $this->arrayKeys = new SplObjectStorage();
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Attribute) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof ClassLike && $node !== $this->root) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof ArrayItem && $node->key instanceof String_) {
            $this->arrayKeys[$node->key] = null;
        }

        if ($node instanceof ArrayDimFetch && $node->dim instanceof String_) {
            $this->arrayKeys[$node->dim] = null;
        }

        if ($node instanceof String_ && !isset($this->arrayKeys[$node]) && self::counts($node->value)) {
            $this->occurrences[$node->value][] = $node->getStartLine();
        }

        return null;
    }

    /** @return array<string, list<int>> value => lines, in first-seen order */
    public function occurrences(): array
    {
        return $this->occurrences;
    }

    private static function counts(string $value): bool
    {
        return \strlen($value) >= ForbidRepeatedStringLiteralRule::MIN_LENGTH && '' !== trim($value);
    }
}
