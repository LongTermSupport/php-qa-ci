<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans reaching the process's own standard streams inside a class, function or closure: the
 * STDIN, STDOUT and STDERR constants; a php://stdin, php://stdout, php://stderr or php://fd/N
 * stream opened by name, whether the name is written there or held in a variable or constant;
 * Symfony's ConsoleOutput, which opens php://stdout and php://stderr itself; readline(); and an
 * error_log() that goes to the SAPI logger, which is stderr on the command line. Code a test can
 * call then reaches past the test runner, so its output can be neither captured nor asserted, and
 * a write to stderr makes Infection stop its initial test run. The stream belongs in a parameter,
 * which the entry script supplies.
 *
 * Two places are left alone, because no test calls them: script-level code, which runs only as
 * its own process's entry script, and a PHPUnit runner extension, which the runner calls. `echo`
 * and `print` go through the output buffer PHPUnit captures, so they are not this rule's concern.
 *
 * See: docs/phpstan-rules/forbid-process-stream.md.
 *
 * @implements Rule<Expr>
 */
final readonly class ForbidProcessStreamRule implements Rule
{
    /** The identifier every finding carries, resolved by rule-doc. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.processStream';

    /** The constants PHP's CLI defines for the process's own streams. */
    private const array STREAM_CONSTANTS = ['STDIN', 'STDOUT', 'STDERR'];

    /** The functions that open a stream from the name in their first argument; Safe's wrappers too. */
    private const array OPENING_FUNCTIONS = ['fopen', 'file_put_contents', 'file_get_contents', 'file', 'readfile'];

    /** How a call to one of Safe's wrappers begins, lower-cased. */
    private const string SAFE_NAMESPACE = 'safe\\';

    /** The class that opens a stream from the name its constructor is given, lower-cased. */
    private const string FILE_OBJECT = 'splfileobject';

    /** Symfony's output for the process's own stdout and stderr, lower-cased. */
    private const string CONSOLE_OUTPUT = 'symfony\component\console\output\consoleoutput';

    /** How every readline function's name begins; each reads the process's stdin. */
    private const string READLINE = 'readline';

    /** The function whose default destination is the SAPI logger. */
    private const string ERROR_LOG = 'error_log';

    /** error_log()'s message types that do not go to the SAPI logger: mail (1) and a file (3). */
    private const array ERROR_LOG_ELSEWHERE = [1, 3];

    /** A stream name that opens one of the process's own descriptors, in any case. */
    private const string PROCESS_STREAM_NAME = '~^php://(stdin|stdout|stderr|fd/)~i';

    /** What a PHPUnit runner extension implements; ::class does not load it, so PHPUnit need not be installed. */
    private const string RUNNER_EXTENSION = \PHPUnit\Runner\Extension\Extension::class;

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isInsideAFunction($scope) || $this->isARunnerExtension($scope)) {
            return [];
        }

        $stream = $this->streamReachedBy($node, $scope);
        if (null === $stream) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                $stream . " inside a function reaches the process's own stream, which a test can neither capture nor assert,"
                . " and a write to stderr stops Infection's initial test run. Take the stream as a parameter and let the entry script pass it."
                . ' See docs/phpstan-rules/forbid-process-stream.md.',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function streamReachedBy(Node $node, Scope $scope): ?string
    {
        if ($node instanceof ConstFetch) {
            $constant = $node->name->toString();

            return \in_array($constant, self::STREAM_CONSTANTS, true) ? $constant : null;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            return $this->streamReachedByCall($this->functionName($node->name), $node, $scope);
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $class = $node->class->toLowerString();
            if (self::CONSOLE_OUTPUT === $class) {
                return 'new ConsoleOutput()';
            }

            if (self::FILE_OBJECT === $class) {
                return $this->processStreamNamedBy($node->args[0] ?? null, $scope);
            }
        }

        return null;
    }

    private function streamReachedByCall(string $function, FuncCall $call, Scope $scope): ?string
    {
        if (\in_array($function, self::OPENING_FUNCTIONS, true)) {
            return $this->processStreamNamedBy($call->args[0] ?? null, $scope);
        }

        if (str_starts_with($function, self::READLINE)) {
            return $function . '()';
        }

        if (self::ERROR_LOG === $function && $this->logsToTheSapiLogger($call->args[1] ?? null, $scope)) {
            return 'error_log() to the SAPI logger';
        }

        return null;
    }

    /** The function's lower-case name, with Safe's namespace taken off its wrappers. */
    private function functionName(Name $name): string
    {
        $function = $name->toLowerString();

        return str_starts_with($function, self::SAFE_NAMESPACE) ? substr($function, \strlen(self::SAFE_NAMESPACE)) : $function;
    }

    /** The process stream the argument names, written there or held in a variable or constant. */
    private function processStreamNamedBy(?Node $argument, Scope $scope): ?string
    {
        if (!$argument instanceof Arg) {
            return null;
        }

        foreach ($scope->getType($argument->value)->getConstantStrings() as $name) {
            if (1 === \Safe\preg_match(self::PROCESS_STREAM_NAME, $name->getValue())) {
                return $name->getValue();
            }
        }

        return null;
    }

    /** True unless the message type is known to be mail or a file. */
    private function logsToTheSapiLogger(?Node $messageType, Scope $scope): bool
    {
        if (!$messageType instanceof Arg) {
            return true;
        }

        $types = $scope->getType($messageType->value)->getConstantScalarValues();
        if ([] === $types) {
            return true;
        }

        return array_any($types, static fn ($type): bool => !\in_array($type, self::ERROR_LOG_ELSEWHERE, true));
    }

    /**
     * A class counts as a whole: a parameter default is analysed in the scope around its method,
     * yet a call that leaves the argument out gets the process's stream all the same.
     */
    private function isInsideAFunction(Scope $scope): bool
    {
        return $scope->isInClass() || $scope->getFunction() instanceof \PHPStan\Reflection\Php\PhpFunctionFromParserNodeReflection || $scope->isInAnonymousFunction();
    }

    private function isARunnerExtension(Scope $scope): bool
    {
        $class = $scope->getClassReflection();

        return $class instanceof \PHPStan\Reflection\ClassReflection && $class->implementsInterface(self::RUNNER_EXTENSION);
    }
}
