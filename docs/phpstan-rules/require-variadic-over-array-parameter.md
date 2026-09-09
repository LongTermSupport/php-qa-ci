# `phpqaci.variadicOverArrayParameter` — a docblock-typed list parameter should be variadic

**Rule**: `RequireVariadicOverArrayParameterRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A method or function has a parameter declared `array` whose `@param` docblock types it as
`list<T>` or `non-empty-list<T>`:

```php
/** @param list<string> $baseArgs */
public function build(array $baseArgs): Process
```

The parameter does **not** have to be last already. A variadic must be final, so one that is
not can be moved there and then converted, and the message says so:

```
Parameter $parts of joinWithSuffix() is a list<string> in a docblock;
move it to last and declare it "string ...$parts" so the engine checks it.
```

Only **one** parameter per signature is ever reported, because a signature can hold only one
variadic. The one nearest the end is chosen, since it moves the least.

### `array<T>` is deliberately not reported

`array<T>` with a single type argument looks like it means "a list of `T`", and it does not.
Verified with `\PHPStan\dumpType()`:

| written         | PHPStan resolves to   |
| --------------- | --------------------- |
| `array<string>` | `array<mixed, mixed>` |
| `string[]`      | `array<mixed, mixed>` |
| `list<string>`  | `list<string>`        |

The single argument constrains the **value** type and says nothing about the keys, so an
`array<T>` or `T[]` parameter may well be a map — and converting a map to a variadic silently
discards its keys. Only `list` states the shape a variadic actually provides. If your
`array<T>` really is a list, say `list<T>` and the rule will then report it.

### Docblock refinements in the suggestion

A refinement such as `class-string` or `int<0, max>` has no native spelling, so the message
names the base type the engine can check and leaves the refinement to the docblock:

```php
/** @param list<class-string> $classes */   →   declare it "string ...$classes"
/** @param list<int<0, max>> $offsets */    →   declare it "int ...$offsets"
```

## Why this is a hazard

The docblock states the element type, but PHP's own type checker never sees it — only
PHPStan does, and only where it can analyse the call site. A native variadic parameter
states the same fact in a form PHP itself enforces:

```php
public function build(string ...$baseArgs): Process
```

**Be honest about what this buys you.** At level `max`, PHPStan already type-checks a
`list<T>` argument against a documented `array` parameter at any call site it can see, so
inside one analysed codebase the docblock form is not silently unchecked. What the variadic
form adds:

- **Enforcement at boundaries PHPStan does not analyse.** A consuming project overriding
  this method, calling it through a dynamically resolved callable, or invoking it from code
  outside this analysis run gets no docblock checking at all — the native type is the only
  contract that reaches that caller.
- **Nothing to keep in sync.** A docblock is a second, hand-maintained statement of the
  parameter's type; when the class changes and the comment is not updated, PHPStan trusts
  the stale comment (`treatPhpDocTypesAsCertain`) rather than catching the drift.
- **No comment to write or keep at all** for the common case — the native signature already
  says everything the docblock said.

## What is not flagged

Each of the following makes the array-to-variadic change impossible or would change
behaviour, so none of them is reported:

- The parameter is **promoted** (visibility or `readonly`) — PHP does not allow a promoted
  property to be variadic.
- The parameter is **by-reference** (`&$x`) or **already variadic**.
- The **signature already has a variadic**, so its one slot is spent and nothing else in that
  signature is convertible.
- **The parameter itself, or any parameter after it, carries a default.** Two separate
  language limits, both fatal to the conversion.

  A *later* optional blocks the move to final position, because the resulting call shape
  `f($a, name: $b, ...$args)` is rejected outright:

  ```
  PHP Fatal error: Cannot use argument unpacking after named arguments
  ```

  Every caller that names one of those optionals would stop compiling, and a library cannot
  see its consumers' call sites.

  A default on the parameter *itself* cannot survive at all. A variadic is implicitly
  optional and implicitly empty, and there is no syntax for `string ...$items = ['a']`, so
  converting would silently drop a non-empty default and change what a caller passing
  nothing receives.
- The docblock type is anything other than `list<T>` / `non-empty-list<T>` — including
  `array<T>` and `T[]` (see above), a **map** (`array<K, V>`), an `iterable<K, V>`, a
  **shape/object-like array** (`array{name: string}`), or **no `@param` entry** at all.
- The native declared type is not `array` — an `iterable` parameter accepts a `Traversable`
  too, so a list docblock does not make it convertible.
- The method is **`__construct`** — PHPStan's own neon DI container passes each
  `arguments:` entry as one positional value. A variadic constructor would silently
  re-interpret that one array argument as the first element of a spread instead of the
  whole list.
- The method carries **`#[DataProvider]`** or **`#[DataProviderExternal]`** — PHPUnit
  passes each data-provider row's elements as separate arguments to the test method, so a
  `list<string>` element in a row is ONE argument, and a variadic parameter would change
  what the row means.
- The method **implements an interface method or overrides a parent method** — the
  signature belongs to the supertype, not to this declaration. The interface's or parent
  class's own declaration is still checked, since nothing constrains *its* signature.

## The correct construction

Replace the array parameter and its docblock with a native variadic of the element type,
and delete the now-redundant `@param`:

```php
// Before
/** @param list<string> $baseArgs */
public function build(array $baseArgs): Process
{
    return new Process($baseArgs);
}

// After
public function build(string ...$baseArgs): Process
{
    return new Process($baseArgs);
}
```

Every existing call site that passes an array must switch to argument unpacking:
`$builder->build(...$args)` instead of `$builder->build($args)`.

## A known limit: the rule reads declarations, the hazard can live at call sites

The "any later parameter carries a default" exclusion catches the common case, because a
trailing optional is what makes callers reach for named arguments in the first place. It does
not catch a signature with **no** defaults whose callers still name a required argument —
`runTools($tools, $context, $failed, $retried, gated: true)` hits the same fatal, and the
declaration alone does not show it.

Detecting that properly needs caller analysis, which is possible for a private method and
impossible for a published one. Before converting, check the call sites for named arguments.

## If you believe an instance is legitimate

Move the docblock's meaning into the parameter's real type where possible. Inline
`@phpstan-ignore` is banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case belongs in
`ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier.
