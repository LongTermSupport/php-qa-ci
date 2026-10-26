<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

/**
 * Reports a test that runs git without switching off the host's git configuration.
 *
 * A child process inherits the environment, so git run from a test reads the global and system
 * config of whoever runs the suite: commit signing, hooks, a default branch, `log.showSignature`,
 * an excludes file. The test then observes the machine, not the code, and a signing config also
 * leaves key files in the temp directory. The environment given to the process must set
 * `GIT_CONFIG_GLOBAL` and `GIT_CONFIG_NOSYSTEM`, provably: PHPStan must know both keys are there.
 * It must also switch off `maintenance.auto` and `gc.auto` through `GIT_CONFIG_COUNT` and
 * `GIT_CONFIG_KEY_n`, since `git commit` can start a detached maintenance process that writes into
 * `.git` after the call returned and races the test's cleanup.
 *
 * Recognised: a Symfony Process constructed with, or built by fromShellCommandline from, a git
 * command, and PHP's or Safe's proc_open given one. Only test code is checked (TestCodeDetector:
 * a namespace with a `Tests` segment or a file under the project's `tests/`): production code runs
 * git on the user's own repository, where the user's configuration is wanted.
 *
 * See: docs/phpstan-rules/forbid-unisolated-git-process.md.
 *
 * @implements Rule<CallLike>
 */
final readonly class ForbidUnisolatedGitProcessRule implements Rule
{
    /** The identifier every finding carries, resolved by rule-doc. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unisolatedGitProcess';

    /** The message every finding carries. */
    public const string MESSAGE = "git is run with the host's git configuration: give the process an environment that sets GIT_CONFIG_GLOBAL and GIT_CONFIG_NOSYSTEM and, through GIT_CONFIG_COUNT with GIT_CONFIG_KEY_n and GIT_CONFIG_VALUE_n, switches off maintenance.auto and gc.auto, so neither signing, hooks or a default branch on the machine running the tests nor a detached auto-maintenance process can change what the test sees.";

    /** The variables that switch off the global and the system git configuration. */
    private const array ISOLATING_VARIABLES = ['GIT_CONFIG_GLOBAL', 'GIT_CONFIG_NOSYSTEM'];

    /** The variable that announces how many GIT_CONFIG_KEY_n / GIT_CONFIG_VALUE_n pairs follow. */
    private const string CONFIG_COUNT = 'GIT_CONFIG_COUNT';

    /** The prefix of the variables that name a configuration key. */
    private const string CONFIG_KEY_PREFIX = 'GIT_CONFIG_KEY_';

    /** The configuration keys the environment must set, so no auto-maintenance outlives a git call. */
    private const array MAINTENANCE_KEYS = ['maintenance.auto', 'gc.auto'];

    /** Symfony's Process, lower-cased. */
    private const string PROCESS = 'symfony\component\process\process';

    /** The Process factory that takes a shell command line, lower-cased. */
    private const string FROM_SHELL = 'fromshellcommandline';

    /** The process-opening function, PHP's and Safe's, lower-cased. */
    private const array PROC_OPEN = ['proc_open', 'safe\proc_open'];

    /** The environment is the third argument of Process and fromShellCommandline. */
    private const int PROCESS_ENV_POSITION = 2;

    /** The environment is the fifth argument of the process-opening function. */
    private const int PROC_OPEN_ENV_POSITION = 4;

    /** The environment argument's names, for a named argument: Process's, then the process-opening function's. */
    private const array ENV = ['env', 'env_vars'];

    /** The command argument's name, for a named argument. */
    private const array COMMAND = ['command'];

    /** The program this rule is about. */
    private const string GIT = 'git';

    public function __construct(
        private TestCodeDetector $testCodeDetector,
    ) {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param CallLike $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->testCodeDetector->isTestCode($scope->getFile(), $scope->getNamespace()) || $node->isFirstClassCallable()) {
            return [];
        }

        $envPosition = $this->environmentPosition($node, $scope);
        if (null === $envPosition) {
            return [];
        }

        $args = $node->getArgs();
        if (!$this->runsGit($this->argument(0, self::COMMAND, ...$args), $scope)) {
            return [];
        }

        if ($this->isolates($this->argument($envPosition, self::ENV, ...$args), $scope)) {
            return [];
        }

        return [RuleErrorBuilder::message(self::MESSAGE)->identifier(self::IDENTIFIER)->build()];
    }

    /** Null when the call does not start a process this rule reads. */
    private function environmentPosition(CallLike $node, Scope $scope): ?int
    {
        if ($node instanceof New_) {
            return $node->class instanceof Name && self::PROCESS === strtolower($scope->resolveName($node->class)) ? self::PROCESS_ENV_POSITION : null;
        }

        if ($node instanceof StaticCall) {
            $isFactory = $node->class instanceof Name
                && $node->name instanceof Identifier
                && self::FROM_SHELL === $node->name->toLowerString()
                && self::PROCESS    === strtolower($scope->resolveName($node->class));

            return $isFactory ? self::PROCESS_ENV_POSITION : null;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            return \in_array($node->name->toLowerString(), self::PROC_OPEN, true) ? self::PROC_OPEN_ENV_POSITION : null;
        }

        return null;
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

    private function runsGit(?Expr $command, Scope $scope): bool
    {
        if (!$command instanceof Expr) {
            return false;
        }

        $type = $scope->getType($command);
        if ($type->isString()->yes()) {
            return array_any($type->getConstantStrings(), fn (ConstantStringType $line): bool => $this->shellLineRunsGit($line->getValue()));
        }

        // An array literal's first element is read from the literal: a spread after it hides its offset from the type.
        $first = $command instanceof Array_ && [] !== $command->items && !$command->items[0]->unpack
            ? $scope->getType($command->items[0]->value)
            : $type->getOffsetValueType(new ConstantIntegerType(0));

        return array_any($first->getConstantStrings(), fn (ConstantStringType $program): bool => $this->isGit($program->getValue()));
    }

    /** The first word that is not a `NAME=value` assignment is the program. */
    private function shellLineRunsGit(string $line): bool
    {
        foreach (explode(' ', trim($line)) as $word) {
            if ('' === $word || 1 === \Safe\preg_match('/^[A-Za-z_]\w*=/', $word)) {
                continue;
            }

            return $this->isGit($word);
        }

        return false;
    }

    private function isGit(string $program): bool
    {
        return self::GIT === basename($program);
    }

    private function isolates(?Expr $environment, Scope $scope): bool
    {
        if (!$environment instanceof Expr) {
            return false;
        }

        $type = $scope->getType($environment);

        return array_all(self::ISOLATING_VARIABLES, static fn (string $variable): bool => self::hasKey($type, $variable))
            && self::hasKey($type, self::CONFIG_COUNT)
            && self::disablesMaintenance($type);
    }

    /** True when the environment's GIT_CONFIG_KEY_n entries name every maintenance key. */
    private static function disablesMaintenance(Type $environment): bool
    {
        $named = [];
        foreach ($environment->getConstantArrays() as $array) {
            foreach ($array->getKeyTypes() as $index => $key) {
                foreach ($key->getConstantStrings() as $name) {
                    if (!str_starts_with($name->getValue(), self::CONFIG_KEY_PREFIX)) {
                        continue;
                    }

                    foreach ($array->getValueTypes()[$index]->getConstantStrings() as $setting) {
                        $named[$setting->getValue()] = true;
                    }
                }
            }
        }

        return array_all(self::MAINTENANCE_KEYS, static fn (string $key): bool => isset($named[$key]));
    }

    private static function hasKey(Type $environment, string $variable): bool
    {
        return $environment->isArray()->yes() && $environment->hasOffsetValueType(new ConstantStringType($variable))->yes();
    }
}
