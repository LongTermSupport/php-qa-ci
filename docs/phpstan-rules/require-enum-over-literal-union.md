# `phpqaci.enumOverLiteralUnion` — a docblock literal set is an undeclared enum

**Rule**: `RequireEnumOverLiteralUnionRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A `@param` or `@return` whose type is a union of two or more string or integer literals
(optionally with `null`), on a function-like whose native type is `string`, `int`, a
nullable or union form of those, or no native type at all.

```php
/**
 * @param 'TokenGet'|'TokenRefresh' $operation
 */
private function loginResponse(string $operation): string

/** @return 'asc'|'desc' */
public function direction(): string

/** @param 0|1|2 $level */
public function verbosity(int $level): void
```

## Why this is a hazard

The docblock states the whole domain of the value, and then nothing enforces it. Any string
satisfies the native type, so a misspelt `'TokenRefesh'` reaches the body and fails wherever it
is finally compared, not at the call. The set has no home: every method that accepts the value
re-types it in its own docblock, the copies drift, and a reader has to find and compare docblocks
to learn what the value can be. That is an enum expressed as prose — all of the maintenance
burden of a closed set with none of the engine support.

## The correct construction

Declare a backed enum once and type the parameter with it. The set becomes a single declaration
the engine enforces at every call site, exhaustively matchable, and discoverable from the type.

```php
enum LoginOperationEnum: string
{
    case TokenGet     = 'TokenGet';
    case TokenRefresh = 'TokenRefresh';
}

private function loginResponse(LoginOperationEnum $operation): string
{
    $wireName = $operation->value;
    // ...
}
```

Where the value crosses a boundary as a scalar (a wire format, a CLI option), convert at that
boundary with `Enum::from()` / `tryFrom()` and carry the enum inside.

Test code follows the same rule: a helper that accepts `'TokenGet'|'TokenRefresh'` is the same
undeclared enum, and a test-local enum is the fix.

## What is still allowed

- A single literal (`@param 'fixed' $x`, `'fixed'|null`) is a constant, not a set.
- A literal union nested inside a generic (`list<'a'|'b'>`, `array<string, 0|1>`) types the
  element, not this scalar; declare the element's enum and write `list<ThatEnum>`.
- Unions of type names without literals (`string|int`, `non-empty-string|numeric-string`,
  `class-string<A>|class-string<B>`).
- A literal union over a non-scalar native type is already a PHPStan type error and is left to
  PHPStan.

## Narrowing on record

None. The rule is drawn to the pattern exactly: two or more literals in one tag over a scalar
native type. It does not read `@var` on properties; a property carrying a literal-union `@var`
is the next wider rule and is not built because the independent search that motivated this rule
found no instance of it (Defence Before Fix, clause 3.1 step 4).

## If you believe an instance is legitimate

Convert it. Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an
irreducible case belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this
identifier, where it is visible and reviewable.
