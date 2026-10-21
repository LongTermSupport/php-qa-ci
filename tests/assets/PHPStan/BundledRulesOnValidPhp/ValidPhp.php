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
 * Assertion-named methods, so the opt-in magic-string rule has calls to judge.
 *
 * @api
 */
final class Assertions
{
    public function assertSame(mixed $expected, mixed $actual): bool
    {
        return $expected === $actual;
    }

    public static function assertEquals(mixed $expected, mixed $actual): bool
    {
        return $expected === $actual;
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
     * Pipes (PHP 8.5) into first-class callables of a Safe\, a global and a
     * namespaced function, a method and a static method, and into a closure,
     * compared with false, tested as conditions, under @ and assigned.
     */
    public function pipes(string $path, Target $target): string
    {
        $compared   = false === ($path |> \Safe\realpath(...)) || ($path |> \strlen(...)) !== false;
        $suppressed = false === @($path |> \Safe\realpath(...));
        $chained    = $path |> \trim(...) |> \Safe\realpath(...) |> namespacedFunction(...);
        $method     = $path |> $target->run(...) |> Target::make(...);
        $closure    = $path |> (static fn (string $value): string => $value);

        if ($path |> \Safe\realpath(...)) {
            return 'condition';
        }

        if (!($resolved = $path |> \Safe\realpath(...))) {
            return 'assigned';
        }

        return ($path |> \Safe\file_get_contents(...)) ? $chained . $method . $closure . $resolved : \gettype($compared || $suppressed);
    }

    /**
     * Assertion methods called, and taken as first-class callables, whose
     * getArgs() has no expected and actual value.
     */
    public function assertions(Assertions $assert, string $plain): bool
    {
        $same   = $assert->assertSame(...);
        $equals = Assertions::assertEquals(...);
        $static = $assert::assertEquals(...);

        return $same($plain, 'token') && $equals('token', $plain) && $static($plain, $plain);
    }

    /**
     * One finding from each rule set and the opt-in rule, so a run that loaded
     * none of them cannot pass.
     */
    public function firing(?string $value, Assertions $assert, string $plain): string
    {
        return $assert->assertSame('token', $plain) && empty($value) ? 'empty' : ($value ?? '');
    }

    /**
     * Keys made without an element write, in the argument shapes a key rule
     * tends to miss: named and spread arguments, first-class callables,
     * dynamic and anonymous iterators, unnamed and misplaced inline @var
     * tags, yield from and a callback that asks for its arguments.
     *
     * @param list<string> $names
     *
     * @return \Generator<array-key, mixed>
     */
    public function arrayKeys(array $names, string $class, mixed $decoded): \Generator
    {
        $merged   = array_merge(...[[$class => 1], ['x' => 2]]);
        $replaced = array_replace(array: [$class => 1], replacements: ['y' => 2]);
        $flip     = array_flip(...);
        $mapped   = array_map(array: [$class => 1], callback: strval(...));
        $reversed = array_reverse(preserve_keys: true, array: $merged);
        $sliced   = array_slice($replaced, 0, null, ...[true]);
        $made     = iterator_to_array(new $class());
        $anon     = iterator_to_array(new class([]) extends \ArrayIterator {});
        $files    = iterator_to_array(new \FilesystemIterator($class, ...[\FilesystemIterator::KEY_AS_FILENAME]));
        array_walk($merged, static function (int ...$all): void {});
        array_any($names, static fn (): bool => [] !== \func_get_args());
        /** @var array<string, int> */
        $asserted = $decoded;
        /** @var array<string, int> $decoded */
        if (\is_array($decoded)) {
            yield from $decoded;
        }

        foreach ($names as [$name => $value]) {
            $counted = \count(array_keys(...[$merged])) . "{$name}" . $value . compact('name');
        }

        yield from [$flip, $mapped, $reversed, $sliced, $made, $anon, $files, $asserted, $counted ?? ''];
    }

    /**
     * Arrays handed on in the shapes a key rule follows: references, static,
     * global and $GLOBALS, yield, operators and branches, destructuring under
     * an inline @var, ArrayObject, a method generic in its keys, a
     * by-reference parameter, a variable variable and a goto.
     *
     * @param list<array{string, mixed}> $rows
     * @param array<string, int>         $out
     *
     * @return \Generator<int, array<array-key, int>, mixed, array<array-key, int>>
     */
    public function handedOn(array $rows, string $name, bool $c, array &$out): \Generator
    {
        static $cache = [];
        global $registry;
        $cache[$name]               = 1;
        $registry[$name]            = 1;
        $GLOBALS['registry'][$name] = 1;
        $map                        = [];
        $alias                      = &$map;
        $alias[$name]               = 1;
        $map += [$name => 2];
        $map['x'] ??= 3;
        $picked = match ($c) {
            true    => $map + $cache,
            default => $c ? [$name => 1] : (array) $out,
        };
        foreach ($rows as &$row) {
            $row[0] .= $name;
        }

        unset($row);
        /** @var array<string, int> $decoded */
        [$first, $decoded] = $rows[0] ?? ['', []];
        $object            = new \ArrayObject($map);
        $kept              = $this->kept($picked);
        $out[$name]        = \count($object->getArrayCopy()) + \count($kept);
        $variable          = 'name';
        $out[$$variable]   = 1;
        $chunks            = array_chunk($map, 2, true);
        $encoded           = json_encode($map, \JSON_FORCE_OBJECT) . http_build_query($map, 'p_') . $first;
        array_multisort($map);

        yield [$name => 1];
        yield $map;

        return [$encoded => \count($chunks)] + (\is_array($decoded) ? $decoded : []);
    }

    public function opaque(string $name): int
    {
        $map = [];
        again:
        $map[$name] = 1;
        if ([] === $map) {
            goto again;
        }

        return \count($map);
    }

    /**
     * @template K of array-key
     *
     * @param array<K, int> $map
     *
     * @return array<K, int>
     */
    private function kept(array $map): array
    {
        return $map;
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
