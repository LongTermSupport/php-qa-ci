<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Concat as ConcatAssign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Collects each call that runs a command with its output captured, where the command can be read
 * as one that runs PHP and leaves `display_errors` to the host's ini, and each write to a local
 * variable with what it says about the command it holds, so a captured call given a variable can
 * be judged by the last write to it.
 *
 * Captured: a Symfony `Process` (built by `new` or by `fromShellCommandline()`), this package's
 * `ProcessSpecDto`, whose runner hands back what the child printed, and Composer's
 * `ProcessExecutor::execute()` given its `$output` argument. A command runs PHP when its program,
 * the first argv element that is not spread, or the first word of a shell line that is not a
 * `NAME=value` assignment, is `PHP_BINARY`, a `phpBinPath` property, `php` or a path to a binary
 * named `php` with an optional version, or a `??` or `?:` one of whose sides is. The command sends
 * PHP's warnings away from stdout when any part of it sets `display_errors` to `stderr`, `0`,
 * `off`, `no` or `false`.
 *
 * Each value lists [kind, line, function key, variable, verdict] records. A {@see self::CALL}
 * names no variable when its command was read in place, and its verdict is then `false`; given a
 * variable, its verdict is null and the rule reads the variable's writes. A {@see self::WRITE}'s
 * verdict is `true` for a command that sends the warnings away, `false` for one that does not,
 * and null for anything not shown to run PHP.
 *
 * @internal
 *
 * @implements Collector<Node, list<array{string, int, string, string, bool|null}>>
 */
final readonly class CapturedPhpOutputCollector implements Collector
{
    /** A record of a call whose output is captured. */
    public const string CALL = 'call';

    /** A record of a write to a local variable. */
    public const string WRITE = 'write';

    /** Symfony's Process, lower-cased. */
    private const string PROCESS = 'symfony\component\process\process';

    /** This package's process specification, lower-cased: its runner captures what the child prints. */
    private const string PROCESS_SPEC = 'lts\phpqa\pipeline\process\dto\processspecdto';

    /** The Process factory that takes a shell command line, lower-cased. */
    private const string FROM_SHELL = 'fromshellcommandline';

    /** Composer's process runner, which captures only when given its output argument. */
    private const string PROCESS_EXECUTOR = \Composer\Util\ProcessExecutor::class;

    /** The ProcessExecutor method that runs a command, lower-cased. */
    private const string EXECUTE = 'execute';

    /** The command argument's name, for a named argument. */
    private const array COMMAND = ['command'];

    /** ProcessExecutor::execute()'s output argument's name, for a named argument. */
    private const array OUTPUT = ['output'];

    /** The constant holding the path of the PHP binary that runs the analysed code. */
    private const string PHP_BINARY = 'PHP_BINARY';

    /** The property this package, and a project following it, names the configured PHP binary by. */
    private const string PHP_BIN_PATH = 'phpBinPath';

    /** The functions that quote a program for a shell line, lower-cased. */
    private const array SHELL_QUOTES = ['escapeshellarg', 'escapeshellcmd'];

    /** A binary named php, with an optional version such as php8.5. */
    private const string PHP_NAME = '/^php(\d+(\.\d+)*)?$/';

    /** A setting of display_errors that keeps PHP's warnings off stdout. */
    private const string ROUTED = '/display_errors\s*=\s*["\']?(?:stderr|0|off|no|false)(?!\w)/i';

    /** A shell word that assigns a variable for the command rather than naming its program. */
    private const string ASSIGNMENT = '/^[A-Za-z_]\w*=/';

    /** The program word a part of a shell line stands for when it is the PHP binary. */
    private const string PHP_WORD = 'php';

    /** Stands for a part of a shell line whose value cannot be read: no program is named by it. */
    private const string UNREAD = "\0";

    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<array{string, int, string, string, bool|null}>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node instanceof Assign) {
            return $node->var instanceof Variable && \is_string($node->var->name) && $this->mayBeCommand($node->expr, $scope)
                ? [[self::WRITE, $node->getStartLine(), $this->functionKey($scope), $node->var->name, $this->verdict($node->expr, $scope)]]
                : null;
        }

        if ($node instanceof ConcatAssign) {
            // Appending the setting routes whatever the variable held; appending anything else leaves it as it was.
            return $node->var instanceof Variable && \is_string($node->var->name) && $this->routes($this->skeleton($node->expr, $scope))
                ? [[self::WRITE, $node->getStartLine(), $this->functionKey($scope), $node->var->name, true]]
                : null;
        }

        if (!$node instanceof CallLike || $node->isFirstClassCallable()) {
            return null;
        }

        $command = $this->capturedCommand($node, $scope);
        if (!$command instanceof Expr) {
            return null;
        }

        $verdict = $this->verdict($command, $scope);
        if (false === $verdict) {
            return [[self::CALL, $node->getStartLine(), $this->functionKey($scope), '', false]];
        }

        return null === $verdict && $command instanceof Variable && \is_string($command->name)
            ? [[self::CALL, $node->getStartLine(), $this->functionKey($scope), $command->name, null]]
            : null;
    }

    /** Variables are grouped by the function that holds them; a closure's share its enclosing function's. */
    private function functionKey(Scope $scope): string
    {
        return \sprintf('%s::%s', $scope->getClassReflection()?->getName(), $scope->getFunctionName());
    }

    /** Only a string or an array can be a command, so a write of anything else replaces none. */
    private function mayBeCommand(Expr $value, Scope $scope): bool
    {
        $type = $scope->getType($value);

        return !$type->isString()->no() || !$type->isArray()->no();
    }

    /** The command a call runs with its output captured, or null when the call is not one. */
    private function capturedCommand(CallLike $node, Scope $scope): ?Expr
    {
        $args = $node->getArgs();
        if ($node instanceof New_) {
            $class = $node->class instanceof Name ? strtolower($scope->resolveName($node->class)) : null;

            return \in_array($class, [self::PROCESS, self::PROCESS_SPEC], true) ? $this->argument(0, self::COMMAND, ...$args) : null;
        }

        if ($node instanceof StaticCall) {
            $isFactory = $node->class instanceof Name
                && $node->name instanceof Identifier
                && self::FROM_SHELL === $node->name->toLowerString()
                && self::PROCESS    === strtolower($scope->resolveName($node->class));

            return $isFactory ? $this->argument(0, self::COMMAND, ...$args) : null;
        }

        $isExecute = ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Identifier
            && self::EXECUTE === $node->name->toLowerString()
            && new ObjectType(self::PROCESS_EXECUTOR)->isSuperTypeOf($scope->getType($node->var))->yes();

        return $isExecute && $this->argument(1, self::OUTPUT, ...$args) instanceof Expr ? $this->argument(0, self::COMMAND, ...$args) : null;
    }

    /** @param list<string> $names the names the argument may be given by */
    private function argument(int $position, array $names, Arg ...$args): ?Expr
    {
        foreach ($args as $index => $arg) {
            if ($arg->name instanceof Identifier) {
                if (\in_array($arg->name->toString(), $names, true)) {
                    return $arg->value;
                }

                continue;
            }

            if ($index === $position && !$arg->unpack) {
                return $arg->value;
            }
        }

        return null;
    }

    /** True when the command runs PHP and keeps its warnings off stdout, false when it runs PHP and does not, null otherwise. */
    private function verdict(Expr $command, Scope $scope): ?bool
    {
        if ($command instanceof Array_) {
            return $this->argvVerdict($command, $scope);
        }

        $type = $scope->getType($command);
        if ($type->isArray()->yes()) {
            return $this->constantArgvVerdict($type);
        }

        return $type->isString()->yes() ? $this->shellLineVerdict($command, $scope) : null;
    }

    private function argvVerdict(Array_ $argv, Scope $scope): ?bool
    {
        $program = array_find($argv->items, static fn (ArrayItem $item): bool => !$item->unpack);
        if (!$program instanceof ArrayItem || !$this->isPhpProgram($program->value, $scope)) {
            return null;
        }

        return array_any($argv->items, fn (ArrayItem $item): bool => $this->carriesRouting($scope->getType($item->value)));
    }

    /** An argv PHPStan knows the value of, such as a variable or constant holding a literal list. */
    private function constantArgvVerdict(Type $type): ?bool
    {
        $verdicts = [];
        foreach ($type->getConstantArrays() as $argv) {
            $values = $argv->getValueTypes();
            if ([] === $values || !array_any($values[0]->getConstantStrings(), fn (ConstantStringType $program): bool => $this->isPhpName($program->getValue()))) {
                continue;
            }

            $verdicts[] = array_any($values, $this->carriesRouting(...));
        }

        return [] === $verdicts ? null : array_all($verdicts, static fn (bool $routed): bool => $routed);
    }

    private function shellLineVerdict(Expr $command, Scope $scope): ?bool
    {
        $line    = $this->skeleton($command, $scope);
        $program = array_find(
            explode(' ', str_replace(["\t", "\n"], ' ', trim($line))),
            static fn (string $word): bool => '' !== $word && 1 !== \Safe\preg_match(self::ASSIGNMENT, $word),
        );

        return null !== $program && $this->isPhpName($program) ? $this->routes($line) : null;
    }

    /**
     * The shell line as far as it can be read: literal parts as they are, the PHP binary as `php`,
     * and any other part as a marker no program is named by.
     */
    private function skeleton(Expr $expr, Scope $scope): string
    {
        if ($expr instanceof Concat) {
            return $this->skeleton($expr->left, $scope) . $this->skeleton($expr->right, $scope);
        }

        if ($expr instanceof InterpolatedString) {
            return implode('', array_map(
                fn (Expr|InterpolatedStringPart $part): string => $part instanceof InterpolatedStringPart ? $part->value : $this->skeleton($part, $scope),
                $expr->parts,
            ));
        }

        if ($this->isPhpBinary($expr, $scope)) {
            return self::PHP_WORD;
        }

        $strings = $scope->getType($expr)->getConstantStrings();

        return 1 === \count($strings) ? $strings[0]->getValue() : self::UNREAD;
    }

    private function isPhpProgram(Expr $expr, Scope $scope): bool
    {
        return $this->isPhpBinary($expr, $scope)
            || array_any($scope->getType($expr)->getConstantStrings(), fn (ConstantStringType $program): bool => $this->isPhpName($program->getValue()));
    }

    /** An expression naming the PHP binary by what it is rather than by its value. */
    private function isPhpBinary(Expr $expr, Scope $scope): bool
    {
        return match (true) {
            $expr instanceof ConstFetch                                                                                  => self::PHP_BINARY                                                                                                                         === ltrim($expr->name->toString(), '\\'),
            $expr instanceof PropertyFetch, $expr instanceof NullsafePropertyFetch, $expr instanceof StaticPropertyFetch => $expr->name instanceof Identifier && self::PHP_BIN_PATH                                                                                  === $expr->name->toString(),
            $expr instanceof FuncCall                                                                                    => $expr->name instanceof Name
                && \in_array($expr->name->toLowerString(), self::SHELL_QUOTES, true)
                && [] !== $expr->getArgs()
                && $this->isPhpProgram($expr->getArgs()[0]->value, $scope),
            $expr instanceof Coalesce                                                                                    => $this->isPhpProgram($expr->left, $scope)              || $this->isPhpProgram($expr->right, $scope),
            $expr instanceof Ternary                                                                                     => $this->isPhpProgram($expr->if ?? $expr->cond, $scope) || $this->isPhpProgram($expr->else, $scope),
            default                                                                                                      => false,
        };
    }

    private function isPhpName(string $program): bool
    {
        return 1 === \Safe\preg_match(self::PHP_NAME, basename(trim($program, '\'"')));
    }

    private function carriesRouting(Type $type): bool
    {
        $strings = $type->isArray()->yes() ? $type->getIterableValueType()->getConstantStrings() : $type->getConstantStrings();

        return array_any($strings, fn (ConstantStringType $part): bool => $this->routes($part->getValue()));
    }

    private function routes(string $command): bool
    {
        return 1 === \Safe\preg_match(self::ROUTED, $command);
    }
}
