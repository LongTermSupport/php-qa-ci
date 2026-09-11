# `phpqaci.requireSensitiveParameterAttribute` — `#[\SensitiveParameter]` on plaintext credentials

**Rule**: `RequireSensitiveParameterAttributeRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A parameter whose **name** looks like a credential and whose **type** could hold a plaintext
value (`string`, `?string`, `mixed`, or untyped), without the attribute.

```php
public function login(string $password): bool
public function authenticate(string $apiToken): Session
```

## Why this is a hazard

When an exception is thrown, PHP records every function argument in `Throwable::getTrace()`.
A password passed as a plain string is therefore captured, in clear text, by anything that
renders a trace — the error log, the exception page, Sentry, the CI output, a support ticket
containing a pasted stack trace.

The leak needs no bug of its own. The credential-handling code can be perfectly correct; all
that is required is for *something further down* to throw — a database timeout inside
`login()`, a failed HTTP call, a type error three frames deeper. The trace is assembled from
the whole stack, so a fault anywhere below the call captures the argument above it.

That is what makes it worth a static rule rather than a review habit. There is no line of code
to look at where the leak happens, and it does not occur in testing, because the leak only
appears on the failure path.

PHP 8.2 ships the fix: a parameter marked `#[\SensitiveParameter]` is replaced by a
`SensitiveParameterValue` placeholder in the trace, so the real value never appears.

## The correct construction

**Add the attribute**:

```php
public function login(#[\SensitiveParameter] string $password): bool
{
    ...
}
```

**Or take a value object instead of a string**, which is the stronger fix. An object-typed
parameter is not flagged, because it is not a plaintext value — and the object can control its
own `__toString()` and `__debugInfo()` so it cannot be printed by accident either:

```php
final readonly class PlaintextPassword
{
    public function __construct(#[\SensitiveParameter] private string $value)
    {
    }

    public function verify(string $hash): bool
    {
        return password_verify($this->value, $hash);
    }
}
```

Note the attribute on the constructor parameter: a promoted property is still an argument, so
the constructor frame captures it without one.

**Or hash at the boundary** so no inner frame ever receives the plaintext. The fewer frames a
credential passes through, the smaller the surface.

## What is deliberately not flagged

**Object-typed parameters** — `Credentials $credential` is not a plaintext value.

**Already-hashed or encoded values**, matched by an ignore-substring list: `$hashedPassword`,
`$passwordHash` and similar are not sensitive plaintext, and marking them adds noise without
protecting anything.

The rule matches on parameter *name*, so it is a heuristic net rather than a judge. A credential
under an unusual name is not caught; that is what the class-level review is for, and adding the
attribute anywhere is always safe.

## Related

The [`sensitiveParameterUsage`](../tools/sensitiveParameterUsage.md) lane is the always-on
baseline that fails when `#[\SensitiveParameter]` appears **nowhere** in `src/` — a project that
has never heard of the attribute. This rule is the per-parameter check.

## If you believe an instance is legitimate

A parameter named like a credential that genuinely is not one should be renamed; the name is
misleading to readers as well as to the rule. If it really is a credential and you do not want
it redacted from traces, that is an Owner decision to leak a secret on the failure path, and
belongs in `ignoreErrors` with a path and a justification saying so in those terms.
