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

    private static function overwrite(string &$target, string $value): void
    {
        $target = $value;
    }

    private function &reference(): string
    {
        static $value = '/x/';

        return $value;
    }
}
