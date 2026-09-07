# `phpqaci.nullCoalescingEmptyString` — no `?? ''`

**Rule**: `ForbidNullCoalescingEmptyStringRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

Any null-coalescing expression whose right-hand side is the empty string literal.

```php
$name  = $row['customer_name'] ?? '';
$email = $request->get('email') ?? '';
```

## Why this is a hazard

The empty string is falsy, printable and concatenable, which is exactly what makes it dangerous as a
default: it passes through every downstream check that a missing value should have failed.

An absent name becomes an email addressed to nobody. An absent identifier becomes a lookup that
matches everything or nothing. An absent postcode becomes a delivery record that validates and then
fails at the depot. In each case the empty string travelled through code that would have thrown on
`null`, and the point at which the problem becomes visible is a long way from the point at which the
data went missing.

The hazard is the same one [`phpqaci.nullCoalescingFalse`](forbid-null-coalescing-false.md)
describes: two distinguishable states, deliberately blank and never supplied, are merged into one,
and the merge is irreversible.

## The correct construction

**Check for absence explicitly:**

```php
$name = $row['customer_name'] ?? null;
if (null === $name) {
    throw new \RuntimeException('customer_name missing from row');
}
```

**Or validate at the boundary**, which is the right fix for anything arriving from a request, a
query or a third-party response:

```php
$email = $request->get('email');
if (!\is_string($email) || '' === $email) {
    throw new BadRequestHttpException('email is required');
}
```

Note the second condition. Where the empty string is genuinely invalid, say so, rather than
arranging for it to appear and hoping something later objects.

**Or keep the null and let the type carry it.** A `?string` that stays nullable all the way to the
point of use is honest, and PHPStan will require every consumer to handle both cases. That is more
work than `?? ''` and it is the work the coalesce was avoiding.

## What is still allowed

A non-empty string default is a real decision and is not flagged: `?? 'unknown'`, `?? 'draft'` and
`?? 'en_GB'` all state what the absent case means. Only `''` is banned, because it is the string
that looks like a value and behaves like an absence.

## If you believe an instance is legitimate

Fix it at the boundary rather than suppressing it. Inline `@phpstan-ignore` is banned by
`phpqaci.inlinePhpstanIgnore`; an irreducible case belongs in `ignoreErrors` in the project's
`phpstan.neon`, with a path and this identifier.
