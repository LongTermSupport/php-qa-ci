# `phpqaci.nullCoalescingFalse` — no `?? false`

**Rule**: `ForbidNullCoalescingFalseRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

Any null-coalescing expression whose right-hand side is the constant `false`.

```php
$enabled = $config['feature_enabled'] ?? false;
$isAdmin = $user->getRole() ?? false;
```

## Why this is a hazard

`??` fires when the left-hand side is null or undefined. Defaulting to `false` collapses two states
that are not the same thing:

- **the value is genuinely false** — a decision was made and the answer was no
- **the value is missing** — no decision was made, and nobody noticed

Both arrive downstream as `false`, so the code takes the "no" branch either way. When the cause was
a typo'd key, a renamed config entry, a failed API response or a column that was never populated,
the system behaves as though a deliberate negative answer had been given. Nothing throws, nothing
logs, and the failure surfaces later as "the feature is off for some customers" with no trail back
to the cause.

That makes it error hiding rather than a defaulting convenience: the hazard is the loss of the
distinction, not the value `false` itself.

## The correct construction

**Check for absence explicitly**, and decide what absence means:

```php
$enabled = $config['feature_enabled'] ?? null;
if (null === $enabled) {
    throw new \RuntimeException('feature_enabled is not configured');
}
```

**Or validate at the boundary** so the rest of the code never sees the absent case. This is the
better fix where the value arrives from config, an API or the database, because it moves the check
to the one place that knows what the data is supposed to look like:

```php
final readonly class FeatureConfig
{
    public function __construct(public bool $enabled)
    {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        if (!\array_key_exists('feature_enabled', $raw)) {
            throw new \InvalidArgumentException('feature_enabled missing from config');
        }

        return new self((bool) $raw['feature_enabled']);
    }
}
```

**Or make the type non-nullable** so the question cannot arise. If the value is always known, say so
in the type and the coalesce disappears with it.

## What is still allowed

A meaningful default is not this hazard. `?? 0`, `?? 'draft'` and `?? []` all state a deliberate
value for the absent case, and they are untouched by this rule. Only the constant `false` is
flagged, because `false` is the value that reads as an answer whilst meaning "no answer".

The related [`phpqaci.nullCoalescingEmptyString`](forbid-null-coalescing-empty-string.md) covers
`?? ''`, which fails the same way for the same reason.

## If you believe an instance is legitimate

Fix it at the boundary rather than suppressing it. Inline `@phpstan-ignore` is itself banned by
`phpqaci.inlinePhpstanIgnore`; an irreducible case belongs in `ignoreErrors` in the project's
`phpstan.neon`, with a path and this identifier, where it is visible and reviewable.
