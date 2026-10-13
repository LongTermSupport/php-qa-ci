<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnvalidatedRegexPattern;

use Closure;
use RuntimeException;

final readonly class Reportable
{
    /** @param list<string> $patterns */
    public function __construct(private array $patterns, private string $pattern)
    {
    }

    /** @param list<string> $argv */
    public function patternsFromInput(array $argv, string $subject): void
    {
        preg_match($argv[1], $subject);
        \Safe\preg_match($this->pattern, $subject);
        preg_match_all($this->pattern, $subject);
        preg_replace($this->patterns, '', $subject);
        preg_replace_callback($this->pattern, static fn (array $match): string => '', $subject);
        preg_split($this->pattern, $subject);
        preg_grep($this->pattern, [$subject]);
        preg_filter($this->pattern, '', $subject);
        preg_replace_callback_array([$this->pattern => static fn (array $match): string => ''], $subject);
        \Safe\preg_replace($this->pattern, '', $subject);
        preg_match(subject: $subject, pattern: $this->pattern);
    }

    /** @param list<string> $arguments */
    public function unpackedArguments(array $arguments): void
    {
        preg_match(...$arguments);
    }

    public function quotedWithoutItsDelimiter(string $value, string $subject): void
    {
        preg_match('/^' . preg_quote($value) . '$/', $subject);
    }

    public function joinedWithInput(string $value, string $subject): void
    {
        preg_match('/^' . $value . '$/', $subject);
        preg_match("/^{$value}x/", $subject);
        preg_match(\sprintf('/^%s$/', $value), $subject);
        preg_match(\sprintf($value, 'x'), $subject);
    }

    public function aCatchWithNoFinallyIsNoGuard(string $subject): void
    {
        try {
            preg_match($this->pattern, $subject);
        } catch (RuntimeException $exception) {
            throw $exception;
        }
    }

    public function aFinallyThatRestoresNothingIsNoGuard(string $subject): void
    {
        try {
            preg_match($this->pattern, $subject);
        } finally {
            unset($subject);
        }
    }

    public function aClosureMadeInsideTheGuardRunsOutsideIt(string $subject): Closure
    {
        set_error_handler(static fn (): bool => true);
        try {
            return fn (): false|int => preg_match($this->pattern, $subject);
        } finally {
            restore_error_handler();
        }
    }

    public function theFinallyItselfIsOutsideTheGuard(string $subject): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $subject = trim($subject);
        } finally {
            restore_error_handler();
            preg_match($this->pattern, $subject);
        }
    }

    public function aLocalJoinedWithInput(string $value, string $subject): void
    {
        $pattern = '/^' . $value . '$/';
        preg_match($pattern, $subject);
    }

    public function aLocalOneOfWhoseWritesIsInput(string $value, string $subject, bool $raw): void
    {
        $pattern = '/^' . preg_quote($value, '/') . '$/';
        if ($raw) {
            $pattern = $value;
        }

        preg_match($pattern, $subject);
    }

    public function aParameterAppendedTo(string $pattern, string $subject): void
    {
        $pattern .= 'i';
        preg_match($pattern, $subject);
    }

    public function eachOfAListFromInput(string $subject): void
    {
        foreach ($this->patterns as $each) {
            preg_match($each, $subject);
        }
    }

    /** @param list<string> $pair */
    public function aLocalDestructured(array $pair, string $subject): void
    {
        [$first] = $pair;
        $first .= 'i';
        preg_match($first, $subject);
    }

    public function aLocalAssignedByReference(string $subject): void
    {
        $alias = &$this->reference();
        preg_match($alias, $subject);
    }

    public function builtFromALocalFromInput(string $value, string $suffix, string $subject): void
    {
        $raw = $value;
        preg_match('/^' . $raw . '/', $subject);
        preg_match('/^' . preg_quote($value, '/') . ('' === $suffix ? '' : $suffix) . '/', $subject);
    }

    public function quotedWithAnotherDelimiter(string $value, string $subject): void
    {
        preg_match('%^' . preg_quote($value, '/') . '%', $subject);
        preg_match(\sprintf('#%s#', preg_quote($value, '/')), $subject);
        preg_match('/^' . preg_quote($value, $value) . '/', $subject);
    }

    public function restoredWithoutInstallingAHandler(string $pattern, string $subject): void
    {
        try {
            preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }
    }

    public function overwrittenThroughAReference(string $value, string $subject): void
    {
        $pattern = '/a/';
        self::overwrite($pattern, $value);
        preg_match($pattern, $subject);
    }

    public function changedThroughAnAlias(string $value, string $subject): void
    {
        $pattern = '/a/';
        $alias   = &$pattern;
        $alias   = $value;
        preg_match($pattern, $subject);
    }

    public function writtenThroughAVariableVariable(string $name, string $value, string $subject): void
    {
        $pattern = '/a/';
        $$name   = $value;
        preg_match($pattern, $subject);
    }

    /** @param array<string, string> $values */
    public function writtenByExtract(array $values, string $subject): void
    {
        $pattern = '/a/';
        extract($values);
        preg_match($pattern, $subject);
    }

    public function passedToACallThatCannotBeResolved(callable $callback, string $subject): void
    {
        $pattern = '/a/';
        $callback($pattern);
        preg_match($pattern, $subject);
    }

    public function writtenByAClosureThroughItsUse(string $value, string $subject): void
    {
        $pattern = '/a/';
        $write   = static function () use (&$pattern, $value): void {
            $pattern = $value;
        };
        $write();
        preg_match($pattern, $subject);
    }

    public function aHandlerInstalledOnlyInAClosureNeverCalled(string $pattern, string $subject): Closure
    {
        $install = static fn (): mixed => set_error_handler(static fn (): bool => true);
        try {
            preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        return $install;
    }

    public function aliasedThroughAnArrayReference(string $value, string $subject): void
    {
        $pattern = '/a/';
        $refs    = [&$pattern];
        $refs[0] = $value;
        preg_match($pattern, $subject);
    }

    public function writtenByAnInclude(string $file, string $subject): void
    {
        $pattern = '/a/';
        include $file;
        preg_match($pattern, $subject);
    }

    public function changedThroughAnOffset(string $value, string $subject): void
    {
        $pattern    = '/a/';
        $pattern[1] = $value;
        preg_match($pattern, $subject);
    }

    public function aliasedForACallThatTakesItsArgumentsAsAnArray(string $value, string $subject): void
    {
        $pattern = '/a/';
        call_user_func_array([self::class, 'overwrite'], [&$pattern, $value]);
        preg_match($pattern, $subject);
    }

    public function changedThroughAForeachReference(string $value, string $subject): void
    {
        $pattern = '/a/';
        foreach ([&$pattern] as &$entry) {
            $entry = $value;
        }
        preg_match($pattern, $subject);
    }

    public function aliasedForACallThatCannotBeResolved(callable $callback, string $subject): void
    {
        $pattern = '/a/';
        $alias   = &$pattern;
        $callback($alias);
        preg_match($pattern, $subject);
    }

    public function declaredStaticBesideAnOwnedWrite(string $value, string $subject): void
    {
        static $pattern = '/a/';
        $pattern        = '/' . preg_quote($value, '/') . '/';
        preg_match($pattern, $subject);
    }

    public function changedByAnotherCompoundAssignment(int $factor, string $subject): void
    {
        $count = 2;
        $count *= $factor;
        preg_match('/a{' . $count . '}/', $subject);
    }

    public function writtenByAnEval(string $code, string $subject): void
    {
        $pattern = '/a/';
        eval($code);
        preg_match($pattern, $subject);
    }

    /** @param array<string, string> $replacements */
    public function overwrittenAsAForeachKey(array $replacements, string $value, string $subject): void
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        foreach ($replacements as $pattern => $replacement) {
            $subject = $replacement;
        }
        preg_match($pattern, $subject);
    }

    public function reboundToAGlobal(string $value, string $subject): void
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        global $pattern, $unrelated;
        preg_match($pattern, $subject);
    }

    public function overwrittenAsTheFirstOfTwoReferences(string $value, string $subject): void
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        $other   = '';
        self::overwriteBoth($pattern, $other, $value);
        preg_match($pattern, $subject);
    }

    public function overwrittenAsTheSecondOfTwoReferences(string $value, string $subject): void
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        $other   = '';
        self::overwriteBoth($other, $pattern, $value);
        preg_match($pattern, $subject);
    }

    public function overwrittenThroughANamedArgument(string $value, string $subject): void
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        self::overwrite(value: $value, target: $pattern);
        preg_match($pattern, $subject);
    }

    public function overwrittenThroughAVariadicReference(string $value, string $subject): void
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        $other   = '';
        self::overwriteAll($value, $other, $pattern);
        preg_match($pattern, $subject);
    }

    /** @param list<string> $values */
    public function overwrittenFirstByDestructuring(array $values, string $value, string $subject): void
    {
        $pattern           = '/' . preg_quote($value, '/') . '/';
        [$pattern, $other] = $values;
        preg_match($pattern, $subject . $other);
    }

    /** @param list<string> $values */
    public function overwrittenLastByDestructuring(array $values, string $value, string $subject): void
    {
        $pattern           = '/' . preg_quote($value, '/') . '/';
        [$other, $pattern] = $values;
        preg_match($pattern, $subject . $other);
    }

    public function overwrittenByAConstructor(string $value, string $subject): ReferenceTaker
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        $taker   = new ReferenceTaker($pattern, $value);
        preg_match($pattern, $subject);

        return $taker;
    }

    /** @param class-string $class */
    public function passedToAConstructorThatCannotBeResolved(string $class, string $value, string $subject): object
    {
        $pattern  = '/' . preg_quote($value, '/') . '/';
        $instance = new $class($pattern);
        preg_match($pattern, $subject);

        return $instance;
    }

    public function changedThroughAForeachReferenceToItsArray(string $value, string $subject): void
    {
        $patterns = ['/a/', '/b/'];
        foreach ($patterns as &$entry) {
            $entry = $value;
        }
        unset($entry);
        preg_match($patterns[0], $subject);
    }

    public function copiedFromAnArrayChangedThroughAForeachReference(string $value, string $subject): void
    {
        $patterns = ['/a/', '/b/'];
        foreach ($patterns as &$entry) {
            $entry = $value;
        }
        unset($entry);
        foreach ($patterns as $copy) {
            preg_match($copy, $subject);
        }

        $first = $patterns[0];
        preg_match($first, $subject);
    }

    public function copiedFromAVariableChangedThroughAnAlias(string $value, string $subject): void
    {
        $pattern = '/a/';
        $alias   = &$pattern;
        $alias   = $value;
        $copy    = $pattern;
        preg_match($copy, $subject);
    }

    public function changedThroughADestructuringReferenceToItsArray(string $value, string $subject): void
    {
        $patterns     = ['/a/', '/b/'];
        [, &$second]  = $patterns;
        $second       = $value;
        preg_match($patterns[1], $subject);
    }

    public function changedThroughAForeachDestructuringReferenceToItsArray(string $value, string $subject): void
    {
        $rows = [['/a/'], ['/b/']];
        foreach ($rows as [&$entry]) {
            $entry = $value;
        }
        unset($entry);
        preg_match($rows[0][0], $subject);

        $keyed = [['p' => '/a/']];
        foreach ($keyed as ['p' => &$named]) {
            $named = $value;
        }
        unset($named);
        preg_match($keyed[0]['p'], $subject);
    }

    private static function overwrite(string &$target, string $value): void
    {
        $target = $value;
    }

    private static function overwriteBoth(string &$first, string &$second, string $value): void
    {
        $first  = $value;
        $second = $value;
    }

    private static function overwriteAll(string $value, string &...$targets): void
    {
        foreach ($targets as &$target) {
            $target = $value;
        }
    }

    private function &reference(): string
    {
        static $value = '/x/';

        return $value;
    }
}

/** A subclass may declare a constructor taking the pattern by reference. */
class LateStaticallyConstructed
{
    public function make(string $value, string $subject): static
    {
        $pattern = '/' . preg_quote($value, '/') . '/';
        $made    = new static($pattern);
        preg_match($pattern, $subject);

        return $made;
    }
}

final class ReferenceTaker
{
    public function __construct(string &$target, string $value)
    {
        $target = $value;
    }
}
