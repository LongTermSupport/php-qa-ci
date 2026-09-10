# `phpqaci.ambiguousArrayDoc` — a docblock array type must state its keys

**Rule**: `ForbidAmbiguousArrayDocRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A `@param`, `@return` or `@var` tag (also the `@phpstan-` and `@psalm-` prefixed forms) whose
type contains `T[]`, or `array<T>` / `non-empty-array<T>` with a single type argument:

```php
/** @var string[] */
private array $names;

/**
 * @param array<string> $errors
 *
 * @return array<array<string>>
 */
public function check(array $errors): array
```

```text
@param $errors type "array<string>" leaves the keys unstated: write list<T> for a list or array<K, V> for a map.
```

One report per tag, on the tag's own line, however many ambiguous fragments the type holds.

## Why

`T[]` and `array<T>` look like "a list of T" and are not. PHPStan resolves both to
`array<mixed, mixed>`: the single argument constrains the **values** and says nothing about the
keys. Verified with `\PHPStan\dumpType()`:

| written         | PHPStan resolves to   |
| --------------- | --------------------- |
| `array<string>` | `array<mixed, mixed>` |
| `string[]`      | `array<mixed, mixed>` |
| `list<string>`  | `list<string>`        |

So a reader cannot tell a list from a map, `array_is_list()` guarantees nothing at the call
site, and the [variadic rule](require-variadic-over-array-parameter.md) deliberately ignores
both forms because converting a map to a variadic would discard its keys. Stating the shape
makes the docblock mean something and lets the other rules act on it.

## How to fix

Say what the keys are:

| meaning                    | write                      |
| -------------------------- | -------------------------- |
| sequential, `0..n-1`       | `list<T>`                  |
| sequential and never empty | `non-empty-list<T>`        |
| keyed                      | `array<K, V>`              |
| any array, keys irrelevant | `array<array-key, T>`      |
| a fixed set of known keys  | `array{name: string, ...}` |

`preg_match_all` with `PREG_SET_ORDER` returns `list<array<int|string, string>>`;
`json_decode(..., true)` returns `array<array-key, mixed>` at best, and a shape when the
document is known.

## Not reported

- `list<T>`, `non-empty-list<T>`, `array<K, V>`, `iterable<T>`, array shapes, and `array<K, V>` nested inside any of them.
- A tag with no type, and a statement with no docblock.
