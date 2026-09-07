# `phpqaci.emptyCatchBlock` — a catch block must do something

**Rule**: `ForbidEmptyCatchBlockRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

Any `catch` whose body contains no statements.

```php
try {
    $this->cache->invalidate($key);
} catch (\Throwable $e) {
}

try {
    $this->notifier->send($message);
} catch (\Throwable $e) {
    // best effort, ignore
}
```

**Both of these fire.** A comment is not an AST statement, so the second block is empty in exactly
the sense this rule means. That is deliberate: a comment records what somebody believed at the time,
and it does nothing at three in the morning when the exception is actually thrown.

## Why this is a hazard

An empty catch converts a failure into a success. The calling code proceeds as though the operation
completed, because from its point of view nothing went wrong, and every consequence of the failure
arrives later as some other symptom with no connection back to the cause.

It is also the most expensive kind of failure to diagnose, because the evidence was captured and
then discarded. The exception existed, carried a message and a stack trace, and was deliberately
thrown away by code that had it in hand.

## The correct construction

Pick one of these. All three are cheap; the point is that the choice is made and visible.

**Log it**, when the operation genuinely is best-effort and the caller should continue:

```php
try {
    $this->notifier->send($message);
} catch (\Throwable $e) {
    $this->logger->warning('notification failed', ['exception' => $e]);
}
```

**Rethrow it**, wrapped, when the caller needs to know but needs better context:

```php
try {
    $this->repository->save($order);
} catch (\Doctrine\DBAL\Exception $e) {
    throw new OrderPersistenceFailed($order->id, previous: $e);
}
```

Always pass `previous:`. Dropping the original exception discards the stack trace, which recreates
the problem this rule exists to prevent, one layer further out.

**Return a deliberate value**, when absence is a legitimate outcome:

```php
try {
    return $this->client->fetchProfile($id);
} catch (ProfileNotFound $e) {
    return null;
}
```

Note that this catches a **specific** exception. A `return null` under `catch (\Throwable)` is an
empty catch wearing a disguise: it treats a network failure, a bug in the client and a genuine
absence as the same event.

## Narrow the catch, not the handling

Where the impulse to leave the block empty comes from not knowing what might be thrown, the fix is
usually to catch less rather than to handle less. Catching the one exception you can actually
account for lets everything else propagate to somebody who can.

## Related

[`phpqaci.silentCatch`](README.md) is the stricter form, requiring the caught exception to be
referenced in the body rather than merely requiring the body to exist. It catches the case where
something is logged but the exception itself is discarded. It ships in
[`rules-optional.neon`](../../rules-optional.neon).
