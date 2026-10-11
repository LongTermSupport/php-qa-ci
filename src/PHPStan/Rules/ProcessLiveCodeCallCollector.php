<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use Symfony\Component\Process\Process;

/**
 * Collects each call on a Symfony Process after which first-party code runs
 * while the child is alive: start(), and run(), mustRun(), wait() or
 * waitUntil() given a callback. A call with no callback (or a null one) is not
 * collected, because no first-party code runs before it returns, and Symfony
 * stops the child itself before throwing its timeout exception.
 *
 * Each value is [method as declared, printed receiver, line, start offset in
 * the file, the enclosing class and its ancestors, nearest first, whether a
 * callback is given].
 * RequireProcessStopOnThrowRule matches them against the try ranges of
 * ProcessStoppingFinallyCollector and the test cases of
 * ProcessStoppingTearDownCollector.
 *
 * @internal
 *
 * @implements Collector<CallLike, array{string, string, int, int, list<string>, bool}>
 */
final readonly class ProcessLiveCodeCallCollector implements Collector
{
    /** Lower-cased method name => the name Process declares; start() runs code after it, the rest a callback. */
    private const array METHODS = [
        'start'     => 'start',
        'run'       => 'run',
        'mustrun'   => 'mustRun',
        'wait'      => 'wait',
        'waituntil' => 'waitUntil',
    ];

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return array{string, string, int, int, list<string>, bool}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
            return null;
        }

        if (!$node->name instanceof Identifier || $node->isFirstClassCallable()) {
            return null;
        }

        $method = self::METHODS[$node->name->toLowerString()] ?? null;
        if (null === $method) {
            return null;
        }

        $receiverType = TypeCombinator::removeNull($scope->getType($node->var));
        if (!new ObjectType(Process::class)->isSuperTypeOf($receiverType)->yes()) {
            return null;
        }

        $givesACallback = $this->givesACallback($node, $scope);
        if ('start' !== $method && !$givesACallback) {
            return null;
        }

        $class   = $scope->getClassReflection();
        $lineage = $class instanceof \PHPStan\Reflection\ClassReflection ? [$class->getName(), ...$class->getParentClassesNames()] : [];

        return [$method, $this->printer->prettyPrintExpr($node->var), $node->getStartLine(), $node->getStartFilePos(), $lineage, $givesACallback];
    }

    /**
     * The callback is the first positional argument, or the one named `callback`,
     * on every method collected here. An unpacked argument may carry one.
     */
    private function givesACallback(MethodCall|NullsafeMethodCall $call, Scope $scope): bool
    {
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return true;
            }

            $isCallback = null === $arg->name ? 0 === $position : 'callback' === $arg->name->toString();
            if ($isCallback) {
                return !$scope->getType($arg->value)->isNull()->yes();
            }
        }

        return false;
    }
}
