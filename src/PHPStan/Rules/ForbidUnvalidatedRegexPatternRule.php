<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A PCRE call whose pattern the code does not own must run where a pattern
 * that does not compile is reported with its text and where it came from.
 *
 * PCRE does not throw on a bad pattern: it raises a warning and the call
 * returns false, and thecodingmachine/safe turns that into
 * "PREG_INTERNAL_ERROR: Internal error", which names neither. A pattern built
 * from literal strings and preg_quote() is the code's own and is proven by its
 * tests; one from a command line, a configuration file or any parameter is
 * not, so the call runs inside a try whose finally restores an error handler
 * installed to throw naming the pattern.
 *
 * UnownedRegexPatternCollector finds the calls, RegexPatternVariableWriteCollector
 * every write to a local variable, ErrorHandlerRestoringFinallyCollector the
 * guarded ranges and ErrorHandlerInstallCollector each function's body and the
 * handlers installed directly in it. A call is not reported when its pattern is
 * a local variable every write to which is owned, in a function with no write
 * to a variable it cannot name, or when it is inside a guarded range after the
 * innermost function holding it installs a handler. A top-level call also
 * answers for every write any function makes to a top-level variable.
 *
 * See: docs/phpstan-rules/forbid-unvalidated-regex-pattern.md.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidUnvalidatedRegexPatternRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unvalidatedRegexPattern';

    private const string MESSAGE = '%s() compiles a pattern that is not built from literal strings and preg_quote(), so a pattern that does not compile is reported as a bare PCRE warning that names neither the pattern nor where it came from. '
        . 'Compile it under an error handler that throws naming both, restored in a finally. See docs/phpstan-rules/forbid-unvalidated-regex-pattern.md.';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $guards = [];
        foreach ($node->get(ErrorHandlerRestoringFinallyCollector::class) as $file => $collected) {
            $guards[$file] = $collected;
        }

        // File => [body start, body end, where each set_error_handler() directly in it starts] per function.
        $bodies = [];
        foreach ($node->get(ErrorHandlerInstallCollector::class) as $file => $collected) {
            $bodies[$file] = $collected;
        }

        // File => function key => variable => the dependencies of each write to it, null for one not owned;
        // a write any function makes to a top-level variable counts in every file's top-level code.
        $writes       = [];
        $globalWrites = [];
        foreach ($node->get(RegexPatternVariableWriteCollector::class) as $file => $collected) {
            foreach (array_merge(...$collected) as [$functionKey, $variable, $dependencies]) {
                if (RegexPatternVariableWriteCollector::GLOBAL_SCOPE === $functionKey) {
                    $globalWrites[$variable][] = $dependencies;
                } else {
                    $writes[$file][$functionKey][$variable][] = $dependencies;
                }
            }
        }

        $errors = [];
        foreach ($node->get(UnownedRegexPatternCollector::class) as $file => $calls) {
            foreach ($calls as [$function, $line, $position, $dependencies, $functionKey, $named]) {
                $functionWrites = $writes[$file][$functionKey] ?? [];
                if (RegexPatternOwnership::TOP_LEVEL === $functionKey) {
                    foreach ($globalWrites as $variable => $variableWrites) {
                        $functionWrites[$variable] = [...$functionWrites[$variable] ?? [], ...$variableWrites];
                    }
                }

                if ((!$this->hasUnseenWrite($functionWrites, ...$named) && null !== $dependencies && array_all($dependencies, fn (string $variable): bool => $this->isOwned($variable, $functionWrites)))
                    || $this->isGuarded($position, $bodies[$file] ?? [], ...$guards[$file] ?? [])) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf(self::MESSAGE, $function))
                    ->identifier(self::IDENTIFIER)
                    ->file($file)
                    ->line($line)
                    ->build()
                ;
            }
        }

        return $errors;
    }

    /**
     * Whether a variable the pattern names may hold what PHPStan's type for it
     * does not show: its function writes a variable it cannot name, or passes
     * this one, or one it was copied from, to a call whose callee cannot be
     * resolved.
     *
     * @param array<string, list<list<string>|null>> $functionWrites variable => dependencies of each write
     */
    private function hasUnseenWrite(array $functionWrites, string ...$named): bool
    {
        return [] !== $named && (
            \array_key_exists(RegexPatternVariableWriteCollector::ANY_VARIABLE, $functionWrites)
            || array_any(
                array_keys($this->reachable($functionWrites, RegexPatternVariableWriteCollector::COPIED_FROM, ...$named)),
                static fn (string $variable): bool => \array_key_exists(RegexPatternVariableWriteCollector::UNSEEN_WRITE . $variable, $functionWrites),
            )
        );
    }

    /**
     * A variable is owned when it is written and every write is, given the
     * variables each is built from are, and a cycle of them is owned unless
     * something in it is not, so `$p .= 'x'` is judged by the other writes to
     * $p. Every variable it depends on is struck out until none is left to
     * strike; what remains is owned.
     *
     * @param array<string, list<list<string>|null>> $functionWrites variable => dependencies of each write
     */
    private function isOwned(string $variable, array $functionWrites): bool
    {
        $owned = $this->reachable($functionWrites, '', $variable);
        do {
            $struck = false;
            foreach (array_keys($owned) as $candidate) {
                $variableWrites = $functionWrites[$candidate] ?? [];
                if ([] === $variableWrites || array_any(
                    $variableWrites,
                    static fn (?array $dependencies): bool => null === $dependencies
                        || array_any($dependencies, static fn (string $dependency): bool => !isset($owned[$dependency])),
                )) {
                    unset($owned[$candidate]);
                    $struck = true;
                }
            }
        } while ($struck);

        return isset($owned[$variable]);
    }

    /**
     * Every variable reachable from the given ones through the variables each
     * write under the prefixed name lists, each taken once however many
     * routes lead to it.
     *
     * @param array<string, list<list<string>|null>> $functionWrites variable => dependencies of each write
     *
     * @return array<string, true>
     */
    private function reachable(array $functionWrites, string $prefix, string ...$from): array
    {
        $reached = [];
        $pending = $from;
        while ([] !== $pending) {
            $variable = array_pop($pending);
            if (isset($reached[$variable])) {
                continue;
            }

            $reached[$variable] = true;
            foreach ($functionWrites[$prefix . $variable] ?? [] as $dependencies) {
                $pending = [...$pending, ...$dependencies ?? []];
            }
        }

        return $reached;
    }

    /**
     * In a guarded range, outside any function declared in it, after the
     * innermost function holding the call installs an error handler.
     *
     * @param list<array{int, int, list<int>}>       $bodies    each function's body start, end and set_error_handler() offsets
     * @param array{int, int, list<array{int, int}>} ...$guards
     */
    private function isGuarded(int $position, array $bodies, array ...$guards): bool
    {
        if (!array_any($this->innermostInstalls($position, ...$bodies), static fn (int $install): bool => $install < $position)) {
            return false;
        }

        foreach ($guards as [$start, $end, $excluded]) {
            if ($position < $start || $position >= $end) {
                continue;
            }

            if (!array_any($excluded, static fn (array $span): bool => $position >= $span[0] && $position <= $span[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The handlers installed in the body holding the position that starts last:
     * a function declared inside another starts after it, and the file's
     * top-level code, recorded once per install, at 0.
     *
     * @param array{int, int, list<int>} ...$bodies
     *
     * @return list<int>
     */
    private function innermostInstalls(int $position, array ...$bodies): array
    {
        $start    = -1;
        $installs = [];
        foreach ($bodies as [$bodyStart, $bodyEnd, $bodyInstalls]) {
            if ($position < $bodyStart || $position > $bodyEnd || $bodyStart < $start) {
                continue;
            }

            $installs = $bodyStart > $start ? $bodyInstalls : [...$installs, ...$bodyInstalls];
            $start    = $bodyStart;
        }

        return $installs;
    }
}
