# `phpqaci.newDateTime` — no direct `new DateTime`

**Rule**: `ForbidNewDateTimeRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

Instantiating the mutable `DateTime` class. Test files are skipped.

```php
$now = new DateTime();
$due = new DateTime('+7 days');
```

## Why this is a hazard

`DateTime`'s mutators — `modify()`, `add()`, `sub()`, `setDate()`, `setTime()` — change the
object in place and return `$this`. Because they return something, they read exactly like the
immutable API, so the aliasing is invisible at the call site:

```php
$start = new DateTime('2026-01-01');
$end   = $start;
$end->modify('+1 month');   // $start is now February too
```

The same thing happens across a method boundary, which is where it actually bites. A date
passed into a service is a shared reference; if the callee normalises it — to midnight, to UTC,
to the end of the month — the caller's value changes underneath it. Nothing in either
signature says so, and `readonly` does not help: a readonly property holding a `DateTime` still
exposes a mutable object.

The resulting bug is data-dependent and far from its cause. A report comes out with the wrong
window, and the line that broke it is in a different class that looked like it was just
reading.

## The correct construction

**Use `DateTimeImmutable`.** The mutators return a new instance, so aliasing cannot bite:

```php
$start = new DateTimeImmutable('2026-01-01');
$end   = $start->modify('+1 month');   // $start is unchanged
```

**Inject a clock rather than reading the wall clock in domain code.** `new DateTimeImmutable()`
inside a service is an untestable dependency on "now"; PSR-20 makes it an argument:

```php
public function __construct(private ClockInterface $clock)
{
}

public function isOverdue(Invoice $invoice): bool
{
    return $invoice->dueAt < $this->clock->now();
}
```

**Converting an existing mutable instance** at a boundary you do not control:

```php
$immutable = DateTimeImmutable::createFromMutable($legacyDate);
```

Type hints should say `DateTimeImmutable`, not `DateTimeInterface`, when you rely on
immutability — `DateTimeInterface` is satisfied by the mutable class too, so it re-opens the
hole the rule closes.

## What is still allowed

Test files are skipped: constructing a mutable `DateTime` to assert behaviour against it, or to
exercise a third-party API that demands one, is legitimate and contained.

`DateTimeImmutable`, `DateInterval`, `DatePeriod` and `DateTimeZone` are untouched. Receiving a
`DateTime` from a library you do not own is not flagged either — only constructing one is.

## If you believe an instance is legitimate

The usual case is a third-party API that requires a mutable `DateTime` argument. Build it with
`DateTime::createFromImmutable()` at the call site, keep it local to that one statement, and let
the rest of the code hold the immutable value.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
