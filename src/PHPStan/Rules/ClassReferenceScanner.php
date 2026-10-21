<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use Generator;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\MagicConst\File;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;

/**
 * Reads a file's syntax tree for the facts {@see ForbidUnautoloadableClassReferenceRule}
 * needs: every class name the code names and the position it stands in, every class-like
 * it declares, and every file it includes by a path known without running it.
 *
 * A class name is any resolved name in the code that is not a function's, a constant's,
 * a namespace's or an import's. `self`, `static` and `parent` name no other class.
 * Docblocks are not read: a type there loads nothing at run time.
 *
 * The position says whether PHP asks the autoloader for the class there (see
 * {@see ClassReferencePositionEnum}). A type in a method's signature carries what the rule
 * needs to decide whether PHP checks it against an inherited method's: the class's
 * declared ancestors, the method, the parameter (null for the return type) and every
 * class the type names.
 */
final readonly class ClassReferenceScanner
{
    private const string DIRNAME = 'dirname';

    private const string NAME = 'name';

    private const string LINE = 'line';

    private const string POSITION = 'position';

    private const string SIGNATURE = 'signature';

    private const string TYPE = 'type';

    /**
     * @return list<array{name: string, line: int, position: ClassReferencePositionEnum, signature: array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null}>
     */
    public function classReferences(Node ...$nodes): array
    {
        $references = [];
        foreach ($nodes as $node) {
            foreach ($this->references($node, null, null) as $reference) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * Every named class, interface, trait and enum the code declares, at the line of its name.
     *
     * @return list<array{name: string, line: int}>
     */
    public function declaredClasses(Node ...$nodes): array
    {
        $declared = [];
        foreach ($nodes as $node) {
            // PHPStan does not fill a class-like's namespacedName, so the name is joined to its namespace here.
            $namespace = $node instanceof Namespace_ && $node->name instanceof Name ? $node->name->toString() . '\\' : '';
            foreach ($this->walk($node) as $descendant) {
                if (!$descendant instanceof ClassLike || !$descendant->name instanceof Identifier) {
                    continue;
                }

                $declared[] = [self::NAME => $namespace . $descendant->name->toString(), self::LINE => $descendant->name->getStartLine()];
            }
        }

        return $declared;
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
     * The class names under a node. A position given by the parent applies to a name
     * directly under it, or under a nullable, union or intersection type; anything
     * else decides the position of the names under it afresh.
     *
     * @param array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null $signature
     * @param string                                                                                            ...$ancestors the declared ancestors of the innermost class-like
     *
     * @return Generator<int, array{name: string, line: int, position: ClassReferencePositionEnum, signature: array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null}>
     */
    private function references(Node $node, ?ClassReferencePositionEnum $position, ?array $signature, string ...$ancestors): Generator
    {
        if ($node instanceof Use_ || $node instanceof GroupUse) {
            return;
        }

        if ($node instanceof Name) {
            $className = $this->resolvedClassName($node);
            if (null !== $className) {
                yield [
                    self::NAME      => $className,
                    self::LINE      => $node->getStartLine(),
                    self::POSITION  => $position ?? ClassReferencePositionEnum::Loading,
                    self::SIGNATURE => $signature,
                ];
            }

            return;
        }

        if ($node instanceof ClassLike) {
            $ancestors = $this->declaredAncestors($node);
        }

        $isTypeWrapper = $node instanceof NullableType || $node instanceof UnionType || $node instanceof IntersectionType;
        $properties    = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $subNodeName) {
            if ($this->isNotAClassName($node, $subNodeName)) {
                continue;
            }

            $value    = $properties[$subNodeName] ?? null;
            $children = \is_array($value) ? $value : [$value];
            foreach ($children as $index => $child) {
                if (!$child instanceof Node) {
                    continue;
                }

                if ($isTypeWrapper) {
                    yield from $this->references($child, $position, $signature, ...$ancestors);

                    continue;
                }

                if ($node instanceof ClassMethod && \in_array($subNodeName, ['params', 'returnType'], true)) {
                    yield from $this->signatureReferences($node, $child, \is_int($index) && 'params' === $subNodeName ? $index : null, ...$ancestors);

                    continue;
                }

                yield from $this->references($child, $this->positionOf($node, $subNodeName, $position), null, ...$ancestors);
            }
        }
    }

    /**
     * A parameter of a method, or its return type: the type is a signature PHP may check
     * against an inherited method's; the rest of a parameter is read as any other code.
     *
     * @return Generator<int, array{name: string, line: int, position: ClassReferencePositionEnum, signature: array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null}>
     */
    private function signatureReferences(ClassMethod $method, Node $child, ?int $parameter, string ...$ancestors): Generator
    {
        $type = $child instanceof Param ? $child->type : $child;
        if ($child instanceof Param) {
            foreach ($child->getSubNodeNames() as $subNodeName) {
                $value    = get_object_vars($child)[$subNodeName] ?? null;
                $children = \is_array($value) ? $value : [$value];
                foreach ($children as $grandChild) {
                    if ($grandChild instanceof Node && self::TYPE !== $subNodeName) {
                        yield from $this->references($grandChild, null, null, ...$ancestors);
                    }
                }
            }
        }

        if (!$type instanceof Node) {
            return;
        }

        $typeNames = [];
        foreach ($this->walk($type) as $descendant) {
            $className = $descendant instanceof Name ? $this->resolvedClassName($descendant) : null;
            if (null !== $className) {
                $typeNames[] = $className;
            }
        }

        yield from $this->references($type, ClassReferencePositionEnum::MethodSignature, [
            'ancestors' => array_values($ancestors),
            'method'    => $method->name->toString(),
            'parameter' => $parameter,
            'typeNames' => $typeNames,
        ], ...$ancestors);
    }

    /**
     * The position of a class name directly under the node's sub-node; null where the
     * name is one PHP loads, the default. `$incoming` is the position the node itself was
     * given, which marks a `::class` compared with `===` or `!==`.
     */
    private function positionOf(Node $node, string $subNodeName, ?ClassReferencePositionEnum $incoming): ?ClassReferencePositionEnum
    {
        return match (true) {
            $node instanceof Attribute                                                        => ClassReferencePositionEnum::Attribute,
            $node instanceof ClassConstFetch                                                  => $this->classConstantPosition($node, $incoming),
            $node instanceof Identical || $node instanceof NotIdentical                       => ClassReferencePositionEnum::Inert,
            $node instanceof Instanceof_ && 'class'      === $subNodeName                     => ClassReferencePositionEnum::Inert,
            $node instanceof Catch_      && 'types'      === $subNodeName                     => ClassReferencePositionEnum::Inert,
            $this->isTypeOutsideASignature($node, $subNodeName)                               => ClassReferencePositionEnum::Inert,
            default                                                                           => null,
        };
    }

    /**
     * `X::class` is a class name, unless it is an operand of `===` or `!==`; `X::NAME` loads X.
     */
    private function classConstantPosition(ClassConstFetch $fetch, ?ClassReferencePositionEnum $incoming): ?ClassReferencePositionEnum
    {
        if (!$fetch->name instanceof Identifier || 'class' !== $fetch->name->toLowerString()) {
            return null;
        }

        return ClassReferencePositionEnum::Inert === $incoming ? ClassReferencePositionEnum::Inert : ClassReferencePositionEnum::ClassName;
    }

    /**
     * The type of a function's or closure's parameter or return, of a property or of a
     * class constant. A method's parameters and return are read by signatureReferences().
     */
    private function isTypeOutsideASignature(Node $node, string $subNodeName): bool
    {
        if (self::TYPE === $subNodeName) {
            return $node instanceof Param || $node instanceof Property || $node instanceof ClassConst;
        }

        return 'returnType' === $subNodeName
            && ($node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction);
    }

    /**
     * The classes, interfaces and traits a class-like names as its own ancestors.
     *
     * @return list<string>
     */
    private function declaredAncestors(ClassLike $classLike): array
    {
        $names = match (true) {
            $classLike instanceof Class_     => [...($classLike->extends instanceof Name ? [$classLike->extends] : []), ...$classLike->implements],
            $classLike instanceof Interface_ => $classLike->extends,
            $classLike instanceof Enum_      => $classLike->implements,
            default                          => [],
        };
        foreach ($classLike->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                $names = [...$names, ...$stmt->traits];
            }
        }

        $ancestors = [];
        foreach ($names as $name) {
            $className = $this->resolvedClassName($name);
            if (null !== $className) {
                $ancestors[] = $className;
            }
        }

        return $ancestors;
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
            if ($this->isNotAClassName($node, $subNodeName)) {
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

    private function isNotAClassName(Node $node, string $subNodeName): bool
    {
        return self::NAME === $subNodeName
            && ($node instanceof FuncCall || $node instanceof ConstFetch || $node instanceof Namespace_);
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
