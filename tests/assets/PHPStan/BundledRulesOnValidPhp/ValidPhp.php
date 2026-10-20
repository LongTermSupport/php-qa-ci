<?php

declare(strict_types=1);

namespace BundledRulesOnValidPhp;

/**
 * Valid PHP in the parser shapes that most often crash a rule written for the
 * common shape: first-class callables in every call form and position, named
 * and spread arguments, dynamic names, an anonymous class and an enum. Whether
 * a rule reports any of it does not matter; an internal error does.
 *
 * @api
 */
enum Suit: string
{
    case Hearts = 'h';
    case Spades = 's';

    public function label(): string
    {
        return match ($this) {
            self::Hearts => 'Hearts',
            self::Spades => 'Spades',
        };
    }
}

/**
 * @api
 */
final class Target
{
    public ?self $next = null;

    public function run(string $value): string
    {
        return $value;
    }

    public static function make(string $value): string
    {
        return $value;
    }
}

/**
 * @api
 */
final class Shapes
{
    /**
     * First-class callables of a function: namespaced, global and Safe\.
     */
    public function functionCallables(): void
    {
        $safe     = \Safe\realpath(...);
        $global   = \strlen(...);
        $local    = namespacedFunction(...);
        $json     = \Safe\json_decode(...);
        $preg     = \Safe\preg_match(...);
        $contents = \Safe\file_get_contents(...);
        $mkdir    = \Safe\mkdir(...);
        $unsafe   = realpath(...);

        $this->consume($safe, $global, $local, $json, $preg, $contents, $mkdir, $unsafe);
    }

    /**
     * First-class callables compared with false, in either order and in every
     * comparison, and through @ and an assignment.
     */
    public function comparedWithFalse(): bool
    {
        $results = [
            false === \Safe\realpath(...),
            \Safe\realpath(...) === false,
            false !== \Safe\realpath(...),
            false == \Safe\realpath(...),
            \Safe\realpath(...) != false,
            false === @\Safe\realpath(...),
            false === ($callable = \Safe\realpath(...)),
            false === \strlen(...),
            false === namespacedFunction(...),
            false === (new Target())->run(...),
            false === $this->nullable()?->run('x'),
            false === Target::make(...),
        ];

        return \in_array(true, $results, true) && \is_callable($callable);
    }

    /**
     * First-class callables tested as conditions.
     */
    public function asConditions(): string
    {
        if (\Safe\realpath(...)) {
            return 'if';
        }

        if (!\Safe\realpath(...)) {
            return 'not';
        }

        if ($callable = \Safe\realpath(...)) {
            return 'assigned ' . \gettype($callable);
        }

        if (@\Safe\realpath(...)) {
            return 'suppressed';
        }

        while (!\Safe\file_get_contents(...)) {
            return 'while';
        }

        $ternary = \Safe\realpath(...) ? 'a' : 'b';
        $short   = \Safe\realpath(...) ?: null;
        $and     = \Safe\realpath(...) && \strlen(...);
        $or      = (new Target())->run(...) || Target::make(...);
        $nullsafe = $this->nullable()?->run('x') ? 'c' : 'd';

        return $ternary . \gettype($short) . \gettype($and) . \gettype($or) . $nullsafe;
    }

    /**
     * First-class callables of a method and a static method, assigned and passed
     * as arguments, beside a nullsafe call (a nullsafe first-class callable does
     * not compile).
     */
    public function methodCallables(Target $target): void
    {
        $method   = $target->run(...);
        $nullsafe = $target->next?->run('x');
        $static   = Target::make(...);
        $self     = $this->consume(...);

        $this->consume($method, $nullsafe, $static, $self);
        $this->consume(\Safe\realpath(...), \strlen(...), $target->run(...), Target::make(...));
        \array_map(\Safe\realpath(...), ['.']);
        \array_map($target->run(...), ['.']);
        $list = ['b', 'a'];
        \usort($list, \strcmp(...));
        $suppressed = @$target->run(...);
        $this->consume($suppressed);
    }

    /**
     * Named arguments, spread arguments, dynamic function and method names.
     *
     * @param list<string> $args
     */
    public function argumentShapes(Target $target, string $fn, string $method, array $args): string
    {
        $named   = $target->run(value: 'x');
        $static  = Target::make(value: 'y');
        $safe    = \Safe\json_encode(value: ['z'], flags: \JSON_THROW_ON_ERROR);
        $spread  = \sprintf('%s %s', ...$args);
        $dynamic = $fn('x');
        $call    = $target->{$method}('x');
        $static2 = Target::{$method}('x');
        $class   = Target::class;
        $new     = new $class();
        $fcc     = $fn(...);
        $dynFcc  = $target->{$method}(...);
        $nullDyn = $target->next?->{$method}('x');

        if (false === $fn('x')) {
            return 'dynamic';
        }

        if ($target->{$method}('x')) {
            return 'dynamic method';
        }

        return $named . $static . $safe . $spread . \gettype($dynamic) . \gettype($call) . \gettype($static2)
            . \gettype($new) . \gettype($fcc) . \gettype($dynFcc) . \gettype($nullDyn);
    }

    /**
     * An anonymous class, a closure, an arrow function and an enum in use.
     */
    public function otherShapes(): string
    {
        $anonymous = new class {
            public function run(): string
            {
                return 'anonymous';
            }
        };
        $extends = new class extends \ArrayObject {};
        $closure = static fn (string $value): string => $value;
        $legacy  = static function (string $value) use ($closure): string {
            return $closure($value);
        };

        return $anonymous->run() . $legacy('x') . \count($extends) . Suit::from('h')->label()
            . Suit::Spades->value . (Suit::tryFrom('x')?->label() ?? 'none');
    }

    /**
     * One finding from each rule set, so a run that loaded neither cannot pass.
     */
    public function firing(?string $value): string
    {
        return empty($value) ? 'empty' : ($value ?? '');
    }

    private function nullable(): ?Target
    {
        return 0 === random_int(0, 1) ? new Target() : null;
    }

    private function consume(mixed ...$values): void
    {
        \assert([] !== $values);
    }
}

function namespacedFunction(string $value): string
{
    return $value;
}
