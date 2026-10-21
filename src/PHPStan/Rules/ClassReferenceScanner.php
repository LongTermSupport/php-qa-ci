<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use Generator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\MagicConst\File;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;

/**
 * Reads a file's syntax tree for the two facts
 * {@see ForbidUnautoloadableClassReferenceRule} needs: every class name the code
 * names, and every file it includes by a path known without running it.
 *
 * A class name is any resolved name in the code that is not a function's, a
 * constant's, a namespace's or an import's: `new`, a static call or fetch, a class
 * constant, `instanceof`, `extends`, `implements`, a trait use, a `catch`, an
 * attribute and every type declaration. `self`, `static` and `parent` name no other
 * class. Docblocks are not read: a type there loads nothing at run time.
 */
final readonly class ClassReferenceScanner
{
    private const string DIRNAME = 'dirname';

    private const string NAME = 'name';

    /**
     * @return list<array{name: string, line: int}>
     */
    public function classReferences(Node ...$nodes): array
    {
        $references = [];
        foreach ($nodes as $node) {
            foreach ($this->walk($node) as $descendant) {
                if (!$descendant instanceof Name) {
                    continue;
                }

                $className = $this->resolvedClassName($descendant);
                if (null !== $className) {
                    $references[] = [self::NAME => $className, 'line' => $descendant->getStartLine()];
                }
            }
        }

        return $references;
    }

    /**
     * The real path of every existing file the code includes or requires by an
     * absolute path built from literals, `__DIR__`, `__FILE__` and `dirname()`.
     *
     * @return list<string>
     */
    public function includedFiles(string $file, Node ...$nodes): array
    {
        $included = [];
        foreach ($nodes as $node) {
            foreach ($this->walk($node) as $descendant) {
                if (!$descendant instanceof Include_) {
                    continue;
                }

                $path = $this->staticPath($descendant->expr, $file);
                if (null !== $path && str_starts_with($path, '/') && is_file($path)) {
                    $included[] = \Safe\realpath($path);
                }
            }
        }

        return $included;
    }

    /**
     * The node and everything under it, except an import and the name of a function,
     * a constant or a namespace.
     *
     * @return Generator<int, Node>
     */
    private function walk(Node $node): Generator
    {
        if ($node instanceof Use_ || $node instanceof GroupUse) {
            return;
        }

        yield $node;

        // A node's sub-nodes are its public properties; its attributes are not public.
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $subNodeName) {
            if (self::NAME === $subNodeName && ($node instanceof FuncCall || $node instanceof ConstFetch || $node instanceof Namespace_)) {
                continue;
            }

            $value    = $properties[$subNodeName] ?? null;
            $children = \is_array($value) ? $value : [$value];
            foreach ($children as $child) {
                if ($child instanceof Node) {
                    yield from $this->walk($child);
                }
            }
        }
    }

    private function resolvedClassName(Name $name): ?string
    {
        if ($name->isSpecialClassName()) {
            return null;
        }

        if ($name instanceof FullyQualified) {
            return $name->toString();
        }

        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof FullyQualified ? $resolved->toString() : null;
    }

    private function staticPath(Expr $expr, string $file): ?string
    {
        return match (true) {
            $expr instanceof String_  => $expr->value,
            $expr instanceof Dir      => \dirname($file),
            $expr instanceof File     => $file,
            $expr instanceof Concat   => $this->concatenated($expr, $file),
            $expr instanceof FuncCall => $this->dirnameOf($expr, $file),
            default                   => null,
        };
    }

    private function concatenated(Concat $concat, string $file): ?string
    {
        $left  = $this->staticPath($concat->left, $file);
        $right = $this->staticPath($concat->right, $file);

        return null === $left || null === $right ? null : $left . $right;
    }

    private function dirnameOf(FuncCall $call, string $file): ?string
    {
        if ($call->isFirstClassCallable() || !$call->name instanceof Name || self::DIRNAME !== $call->name->toLowerString()) {
            return null;
        }

        $args = $call->getArgs();
        if (1 !== \count($args)) {
            return null;
        }

        $path = $this->staticPath($args[0]->value, $file);

        return null === $path ? null : \dirname($path);
    }
}
