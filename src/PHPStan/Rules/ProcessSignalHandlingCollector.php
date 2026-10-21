<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/**
 * Collects what ForbidUnhandledProcessSignalRule matches: each call that waits
 * for the child of a Symfony Process to end, and so throws
 * ProcessSignaledException when a signal killed it (run(), mustRun(), wait()),
 * and each try statement with a catch naming ProcessSignaledException.
 *
 * A wait in test code is not collected (see the rule). A nullsafe call is also
 * walked as a plain method call at the same offset, so the rule keys calls by
 * offset.
 *
 * A try guards the method calls in its try block alone: a call in a catch or
 * finally block is not guarded by a catch of the same statement. A call in a
 * function, closure or class declared in the try block runs when it is called,
 * which may be after the try has ended, so it is not guarded either.
 *
 * Each value is [CALL or GUARD, the method as Process declares it (empty for a
 * guard), line, start offsets: the call's own, or every call the try guards].
 *
 * @internal
 *
 * @implements Collector<Node, array{string, string, int, list<int>}>
 */
final readonly class ProcessSignalHandlingCollector implements Collector
{
    /** An entry for a call that waits for the child. */
    public const string CALL = 'call';

    /** An entry for a try that catches ProcessSignaledException. */
    public const string GUARD = 'guard';

    /** Lower-cased method name => the name Process declares, for each call that waits for the child to end. */
    private const array METHODS = [
        'run'     => 'run',
        'mustrun' => 'mustRun',
        'wait'    => 'wait',
    ];

    public function __construct(private TestCodeDetector $testCode)
    {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return array{string, string, int, list<int>}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node instanceof TryCatch) {
            return $this->guard($node);
        }

        if ((!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) || !$node->name instanceof Identifier || $node->isFirstClassCallable()) {
            return null;
        }

        $method = self::METHODS[$node->name->toLowerString()] ?? null;
        if (null === $method || $this->testCode->isTestCode($scope->getFile(), $scope->getNamespace())) {
            return null;
        }

        if (!new ObjectType(Process::class)->isSuperTypeOf(TypeCombinator::removeNull($scope->getType($node->var)))->yes()) {
            return null;
        }

        return [self::CALL, $method, $node->getStartLine(), [$node->getStartFilePos()]];
    }

    /**
     * @return array{string, string, int, list<int>}|null
     */
    private function guard(TryCatch $try): ?array
    {
        if (!array_any($try->catches, $this->catchesTheSignal(...))) {
            return null;
        }

        $finder = new NodeFinder();
        $isCall = static fn (Node $found): bool => $found instanceof MethodCall || $found instanceof NullsafeMethodCall;

        // Offset => offset of each call that runs whenever its declaration is called, not in the try.
        $deferred = [];
        foreach ($finder->find($try->stmts, static fn (Node $found): bool => $found instanceof FunctionLike || $found instanceof ClassLike) as $declaration) {
            foreach ($finder->find([$declaration], $isCall) as $call) {
                $deferred[$call->getStartFilePos()] = $call->getStartFilePos();
            }
        }

        $guarded = [];
        foreach ($finder->find($try->stmts, $isCall) as $call) {
            if (!\array_key_exists($call->getStartFilePos(), $deferred)) {
                $guarded[] = $call->getStartFilePos();
            }
        }

        return [self::GUARD, '', $try->getStartLine(), $guarded];
    }

    private function catchesTheSignal(Catch_ $catch): bool
    {
        return array_any($catch->types, static fn (Name $type): bool => 0 === strcasecmp($type->toString(), ProcessSignaledException::class));
    }
}
