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
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans the process's own standard streams inside a function, method or closure: the STDIN, STDOUT
 * and STDERR constants, and a php://stdin, php://stdout, php://stderr or php://fd/N stream opened
 * by name. Code a test can call then writes past the test runner, so its output can be neither
 * captured nor asserted, and a write to stderr makes Infection stop its initial test run. The
 * stream belongs in a parameter, which the entry script supplies.
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

    /** A stream name that opens one of the process's own descriptors, in any case. */
    private const string PROCESS_STREAM_NAME = '~^php://(stdin|stdout|stderr|fd/)~i';

    /** Its class string, not ::class: PHPUnit is a dev dependency, and the rule ships. */
    private const string RUNNER_EXTENSION = 'PHPUnit\Runner\Extension\Extension';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $stream = $this->streamNamedBy($node);
        if (null === $stream || !$this->isInsideAFunction($scope) || $this->isARunnerExtension($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                $stream . ' inside a function reaches the process\'s own stream, which a test can neither capture nor assert,'
                . ' and a write to stderr stops Infection\'s initial test run. Take the stream as a parameter and let the entry script pass it.'
                . ' See docs/phpstan-rules/forbid-process-stream.md.',
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function streamNamedBy(Node $node): ?string
    {
        if ($node instanceof ConstFetch) {
            $constant = $node->name->toString();

            return \in_array($constant, self::STREAM_CONSTANTS, true) ? $constant : null;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name && $this->isAnOpeningFunction($node->name)) {
            return $this->processStreamIn($node->args[0] ?? null);
        }

        if ($node instanceof New_ && $node->class instanceof Name && self::FILE_OBJECT === $node->class->toLowerString()) {
            return $this->processStreamIn($node->args[0] ?? null);
        }

        return null;
    }

    private function isAnOpeningFunction(Name $name): bool
    {
        $function = $name->toLowerString();
        if (str_starts_with($function, self::SAFE_NAMESPACE)) {
            $function = substr($function, \strlen(self::SAFE_NAMESPACE));
        }

        return \in_array($function, self::OPENING_FUNCTIONS, true);
    }

    private function processStreamIn(?Node $first): ?string
    {
        if (!$first instanceof Arg || !$first->value instanceof String_) {
            return null;
        }

        return 1 === preg_match(self::PROCESS_STREAM_NAME, $first->value->value) ? $first->value->value : null;
    }

    /**
     * A class counts as a whole: a parameter default is analysed in the scope around its method,
     * yet a call that leaves the argument out gets the process's stream all the same.
     */
    private function isInsideAFunction(Scope $scope): bool
    {
        return $scope->isInClass() || null !== $scope->getFunction() || $scope->isInAnonymousFunction();
    }

    private function isARunnerExtension(Scope $scope): bool
    {
        $class = $scope->getClassReflection();

        return null !== $class && $class->implementsInterface(self::RUNNER_EXTENSION);
    }
}
