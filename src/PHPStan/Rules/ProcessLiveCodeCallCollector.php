<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Symfony\Component\Process\Process;
use Traversable;

/**
 * Collects what can run first-party code while the child of a Symfony Process
 * is alive.
 *
 * Calls: start(), run(), mustRun(), wait() and waitUntil(), each with whether
 * it is given a callback. start() returns with the child running, a callback
 * runs while it is, and Symfony iterates a Traversable input inside every one
 * of them, callback or not. RequireProcessStopOnThrowRule reports run(),
 * mustRun() or wait() with no callback only when the same receiver is given
 * such an input in the same function; otherwise no first-party code runs
 * before it returns, and Symfony stops the child itself before throwing its
 * timeout exception.
 *
 * Inputs: the constructor, fromShellCommandline() and setInput() given an
 * input whose type is a Traversable or may be one, that is any type the scope
 * cannot prove is not one. The receiver is setInput()'s, and the variable the
 * call's result is assigned to, if it is; a new Process held in no variable has
 * none (UNHELD).
 *
 * Each value is [method as declared, printed receiver or UNHELD, line, start
 * offset in the file, the enclosing class and its ancestors (nearest first),
 * whether a callback is given, the enclosing function, the input's type for an
 * input or null for a call]. RequireProcessStopOnThrowRule matches them against
 * the try ranges of ProcessStoppingFinallyCollector and the test cases of
 * ProcessStoppingTearDownCollector.
 *
 * @internal
 *
 * @implements Collector<Expr, array{string, string, int, int, list<string>, bool, string, string|null}>
 */
final readonly class ProcessLiveCodeCallCollector implements Collector
{
    /** The receiver recorded for a new Process held in no variable; no printed expression reads like it. */
    public const string UNHELD = 'a Process held in no variable';

    /** Lower-cased method name => the name Process declares; start() runs code after it, the rest a callback. */
    private const array METHODS = [
        'start'     => 'start',
        'run'       => 'run',
        'mustrun'   => 'mustRun',
        'wait'      => 'wait',
        'waituntil' => 'waitUntil',
    ];

    /** Lower-cased method name => the name Process declares, for the methods that take an input. */
    private const array INPUT_METHODS = [
        '__construct'          => '__construct',
        'fromshellcommandline' => 'fromShellCommandline',
        'setinput'             => 'setInput',
    ];

    /** The name of the input parameter on every method that takes one. */
    private const string INPUT = 'input';

    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return array{string, string, int, int, list<string>, bool, string, string|null}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        // The variable a Process given an input is assigned to (a new one, or setInput()'s return) holds it too.
        if ($node instanceof Assign) {
            $input = $this->inputGiven($node->expr, $scope);

            return null === $input ? null : $this->entry($input[0], $this->printer->prettyPrintExpr($node->var), $node->expr, $scope, $input[2]);
        }

        if (!$node instanceof CallLike || $node->isFirstClassCallable()) {
            return null;
        }

        $input = $this->inputGiven($node, $scope);
        if (null !== $input) {
            return $this->entry($input[0], $input[1], $node, $scope, $input[2]);
        }

        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
            return null;
        }

        if (!$node->name instanceof Identifier) {
            return null;
        }

        $method = self::METHODS[$node->name->toLowerString()] ?? null;
        if (null === $method || !$this->isAProcess(TypeCombinator::removeNull($scope->getType($node->var)))) {
            return null;
        }

        return $this->entry($method, $this->printer->prettyPrintExpr($node->var), $node, $scope, null, $this->givesACallback($node, $scope));
    }

    /**
     * @return array{string, string, int, int, list<string>, bool, string, string|null}
     */
    private function entry(string $method, string $receiver, Expr $node, Scope $scope, ?string $inputType, bool $givesACallback = false): array
    {
        $class   = $scope->getClassReflection();
        $lineage = $class instanceof ClassReflection ? [$class->getName(), ...$class->getParentClassesNames()] : [];

        return [
            $method,
            $receiver,
            $node->getStartLine(),
            $node->getStartFilePos(),
            $lineage,
            $givesACallback,
            implode('::', [$class?->getName(), $scope->getFunctionName()]),
            $inputType,
        ];
    }

    /**
     * The method, the receiver (UNHELD for a constructor or factory) and the
     * input's type, when a Process is given an input that is or may be a
     * Traversable. An unpacked argument may carry the input, so its type is
     * unknown. A subtype whose constructor or factory has no parameter named
     * `input` is not looked at.
     *
     * @return array{string, string, string}|null
     */
    private function inputGiven(Expr $call, Scope $scope): ?array
    {
        $receiver = self::UNHELD;
        if ($call instanceof New_) {
            $name = '__construct';
            $type = $scope->getType($call);
        } elseif ($call instanceof StaticCall && $call->name instanceof Identifier) {
            $name = $call->name->toLowerString();
            $type = $call->class instanceof Name ? $scope->resolveTypeByName($call->class) : $scope->getType($call->class);
        } elseif (($call instanceof MethodCall || $call instanceof NullsafeMethodCall) && $call->name instanceof Identifier) {
            $name     = $call->name->toLowerString();
            $type     = TypeCombinator::removeNull($scope->getType($call->var));
            $receiver = $this->printer->prettyPrintExpr($call->var);
        } else {
            return null;
        }

        $method = self::INPUT_METHODS[$name] ?? null;
        if (null === $method || !$this->isAProcess($type)) {
            return null;
        }

        $index = null;
        foreach ($type->getMethod($method, $scope)->getVariants() as $variant) {
            foreach ($variant->getParameters() as $position => $parameter) {
                if (self::INPUT === $parameter->getName()) {
                    $index = $position;
                }
            }
        }

        $input = null === $index ? null : $this->argumentType($index, $scope, ...$call->getArgs());
        if (!$input instanceof Type || new ObjectType(Traversable::class)->isSuperTypeOf($input)->no()) {
            return null;
        }

        return [$method, $receiver, $input->describe(VerbosityLevel::typeOnly())];
    }

    private function argumentType(int $index, Scope $scope, Arg ...$args): ?Type
    {
        foreach ($args as $position => $arg) {
            if ($arg->unpack) {
                return new MixedType();
            }

            if ($arg->name instanceof Identifier ? self::INPUT === $arg->name->toString() : $index === $position) {
                return $scope->getType($arg->value);
            }
        }

        return null;
    }

    private function isAProcess(Type $type): bool
    {
        return new ObjectType(Process::class)->isSuperTypeOf($type)->yes();
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
