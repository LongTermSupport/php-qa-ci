# `phpqaci.silentCatch` — a catch block must reference the exception it caught

**Rule**: `ForbidSilentCatchRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A `catch` block that does none of these three things: use the caught variable, re-throw, or
log. A non-capturing `catch (Throwable)` counts as not using it.

```php
try {
    $this->client->send($request);
} catch (Throwable) {
    return null;                 // the exception is gone
}

try {
    $value = $this->decode($raw);
} catch (JsonException $e) {
    $value = [];                 // $e is captured and then ignored
}
```

## Why this is a hazard

The exception is the only record of what went wrong. A block that discards it converts a
specific, diagnosable failure into an ordinary-looking value — `null`, `[]`, `false` — which
then flows onward as though it had been computed. The caller cannot tell a real empty result
from a swallowed `ConnectionTimeout`.

The cost lands later, and on someone else. By the time the wrong value surfaces as a bug
report, the stack trace that would have explained it was destroyed at a line that looked
harmless, and no log line exists to say it ever happened. Debugging starts from the symptom
with no route back to the cause.

This is stricter than [`phpqaci.emptyCatchBlock`](forbid-empty-catch-block.md), which only
catches the empty-body case. A block full of statements that all ignore the exception fails
in exactly the same way, and is more common.

## The correct construction

**Log it, with the exception attached**, when carrying on really is correct:

```php
try {
    $this->cache->warm();
} catch (CacheException $e) {
    $this->logger->warning('cache warm failed; serving cold', ['exception' => $e]);
}
```

The rule accepts any of the PSR-3 levels — `emergency`, `alert`, `critical`, `error`,
`warning`, `notice`, `info`, `debug`, `log`.

**Or re-throw**, wrapping it so the context of this layer is added rather than lost. Pass the
original as `$previous`; that is what keeps the trace:

```php
try {
    $row = $this->connection->fetch($id);
} catch (DriverException $e) {
    throw new UserNotFoundException(\sprintf('no user %s', $id), previous: $e);
}
```

**Or use the exception to decide**, which is the case where catching was the right call to
begin with:

```php
try {
    $this->lock->acquire();
} catch (LockConflictException $e) {
    return RetryDecision::after($e->retryAfter());
}
```

## What is still allowed

Catching and handling is not the hazard — discarding the evidence is. A block that reads the
exception's own data, re-throws it, or logs it passes, however much other work it does.

`catch (Throwable)` without a variable is allowed **when the block throws**, because then the
exception is not being hidden: control is leaving by an exceptional path either way.

## If you believe an instance is legitimate

The usual honest answer is that the `try` is too wide: it wraps several statements when only
one could fail, so the catch has nothing specific to say. Narrow it, and the handling
normally writes itself.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible
case belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this
identifier, where it is visible and reviewable.
