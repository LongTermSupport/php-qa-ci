# `phpqaci.emptyLanguageConstruct` — no `empty()`

**Rule**: `ForbidEmptyLanguageConstructRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

Any use of the `empty()` language construct.

```php
if (empty($array)) { ... }
if (!empty($config['retries'])) { ... }
```

## Why this is a hazard

`empty()` answers one question with two mechanisms, and hides both.

First, it is **true for a whole set of unrelated values**: `0`, `0.0`, `"0"`, `""`, `[]`,
`null` and `false` are all empty. So `empty($retries)` cannot distinguish "retries is not
configured" from "retries is deliberately set to 0" — and those call for opposite behaviour.
The string `"0"` is the one that catches people: a perfectly good value from a form or a CSV
that `empty()` reports as absent.

Second, it **suppresses the undefined-variable and undefined-index diagnostics**. `empty($typo)`
is legal and returns true, where `$typo` alone would be a warning. That makes it the one
construct in the language that turns a misspelling into a silently-taken branch. A renamed
config key keeps working, in the sense that it keeps running and always takes the "missing"
path.

Together those mean an `empty()` check cannot fail loudly. It has an answer for every input,
including the inputs that represent a bug.

## The correct construction

**Say which state you mean**, with a strict comparison:

```php
if ([] === $items) { ... }          // an empty array
if ('' === $name) { ... }           // an empty string
if (null === $value) { ... }        // absent
if (0 === $count) { ... }           // genuinely zero
```

**Test for presence separately from value** when the key may be missing, because those are two
questions:

```php
if (!\array_key_exists('retries', $config)) {
    throw new InvalidArgumentException('retries is not configured');
}

$retries = $config['retries'];
```

**Or type the thing properly at the boundary**, which removes the question entirely and is the
better fix for config, request payloads and database rows:

```php
final readonly class RetryPolicy
{
    public function __construct(public int $retries)
    {
    }
}
```

## What is still allowed

`isset()` is a different question — it asks whether something exists and is non-null, and does
not treat `0` or `""` as absent — so it is not flagged. `??` likewise, subject to
[`phpqaci.nullCoalescingFalse`](forbid-null-coalescing-false.md) and
[`phpqaci.nullCoalescingEmptyString`](forbid-null-coalescing-empty-string.md).

A direct truthiness test on a value you have already typed as `bool` is fine: `if ($isActive)`
is not this rule's concern.

## If you believe an instance is legitimate

Write out the set of values you actually want to treat as empty. If that set really is all
seven, `in_array($v, [0, 0.0, '0', '', [], null, false], true)` states it — and seeing it
written down is usually enough to reveal that it is not what was meant.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
