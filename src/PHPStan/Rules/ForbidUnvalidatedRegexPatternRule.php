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
 * innermost function holding it installs a handler.
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

        // File => function key => variable => the dependencies of each write to it, null for one not owned.
        $writes = [];
        foreach ($node->get(RegexPatternVariableWriteCollector::class) as $file => $collected) {
            foreach (array_merge(...$collected) as [$functionKey, $variable, $dependencies]) {
                $writes[$file][$functionKey][$variable][] = $dependencies;
            }
        }

        $errors = [];
        foreach ($node->get(UnownedRegexPatternCollector::class) as $file => $calls) {
            foreach ($calls as [$function, $line, $position, $dependencies, $functionKey, $named]) {
                $functionWrites = $writes[$file][$functionKey] ?? [];
                $unnamedWrite   = [] !== $named && \array_key_exists(RegexPatternVariableWriteCollector::ANY_VARIABLE, $functionWrites);
                if ((!$unnamedWrite && null !== $dependencies && array_all($dependencies, fn (string $variable): bool => $this->isOwned($variable, $functionWrites)))
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
     * A variable is owned when it is written and every write is, given the
     * variables each is built from are. One already being resolved is taken
     * as owned, so `$p .= 'x'` is judged by the other writes to $p.
     *
     * @param array<string, list<list<string>|null>> $functionWrites variable => dependencies of each write
     * @param list<string>                           $resolving
     */
    private function isOwned(string $variable, array $functionWrites, array $resolving = []): bool
    {
        if (\in_array($variable, $resolving, true)) {
            return true;
        }

        $variableWrites = $functionWrites[$variable] ?? [];

        return [] !== $variableWrites && array_all(
            $variableWrites,
            fn (?array $dependencies): bool => null !== $dependencies
                && array_all($dependencies, fn (string $dependency): bool => $this->isOwned($dependency, $functionWrites, [...$resolving, $variable])),
        );
    }

    /**
     * In a guarded range, outside any function declared in it, after the
     * innermost function holding the call installs an error handler.
     *
     * @param list<array{int, int, list<int>}>       $bodies each function's body start, end and set_error_handler() offsets
     * @param array{int, int, list<array{int, int}>} ...$guards
     */
    private function isGuarded(int $position, array $bodies, array ...$guards): bool
    {
        $installs = $this->innermostBody($position, ...$bodies)[2] ?? [];
        if (!array_any($installs, static fn (int $install): bool => $install < $position)) {
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
     * The body holding the position that starts last: a function declared
     * inside another starts after it, and the file's top-level code at 0.
     *
     * @param array{int, int, list<int>} ...$bodies
     *
     * @return array{int, int, list<int>}|null
     */
    private function innermostBody(int $position, array ...$bodies): ?array
    {
        $innermost = null;
        foreach ($bodies as $body) {
            if ($position >= $body[0] && $position <= $body[1] && (null === $innermost || $body[0] > $innermost[0])) {
                $innermost = $body;
            }
        }

        return $innermost;
    }
}
