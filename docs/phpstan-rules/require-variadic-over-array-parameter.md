# `phpqaci.variadicOverArrayParameter` — a docblock-typed list parameter should be variadic

**Rule**: `RequireVariadicOverArrayParameterRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A method or function's **last** parameter is declared `array` and its `@param` docblock
types it as a homogeneous list — `list<T>`, `non-empty-list<T>`, or `array<T>` with no key
type:

```php
/** @param list<string> $baseArgs */
public function build(array $baseArgs): Process
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
- The docblock type is a **map** (`array<K, V>`, two type arguments), an `iterable<K, V>`,
  a **shape/object-like array** (`array{name: string}`), or there is **no `@param` entry**
  for that parameter at all.
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

## If you believe an instance is legitimate

Move the docblock's meaning into the parameter's real type where possible. Inline
`@phpstan-ignore` is banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case belongs in
`ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier.
