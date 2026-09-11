# `phpqaci.factorySealed` — a factory-sealed class is constructed only by its factory

**Rule**: `FactorySealedRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

`new X(...)` where `X` carries a sealing attribute naming an authorised factory, and the
enclosing class is not that factory.

```php
#[FactorySealedBy(SessionFactory::class)]
final readonly class Session { ... }

// Anywhere that is not SessionFactory:
$session = new Session($id, $userId, $expiresAt);   // flagged
```

## Why this is a hazard

Some objects are only valid if something checked them first — a `Session` whose token was
verified, an `Order` whose lines balance, a `Money` whose currency is known. When the
constructor is public, every call site is a place that invariant can be skipped, and the
skipping is invisible: the object looks identical either way.

PHP has no `friend` or package-private visibility, so "only this other class may construct
this" cannot be said in the language. A private constructor seals the class to *itself*, which
is the wrong shape when the validation logic legitimately lives in a separate factory — you end
up with a public static method on the class doing the factory's job, and the separation you
wanted is gone.

So the constraint has to be checked rather than declared, and the only place it can be checked
is the `new` operator.

## The correct construction

**Go through the factory**, which is where the invariant is established:

```php
$session = $this->sessions->createFor($user);
```

**Declare the seal on the class**, with the package's attribute or a project-defined one:

```php
#[FactorySealedBy(SessionFactory::class)]
final readonly class Session
{
    public function __construct(
        public SessionId $id,
        public UserId $userId,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
```

The attribute is generated into the consumer's own namespace by
[managed source](../../CLAUDE/managed-source.md), so it is a first-party symbol rather than a
dependency. Any project-defined attribute works, provided its first argument is the authorised
factory's class-string; configure the recognised set with the `sealingAttributes` argument in
`rules-optional.neon`.

**If a second place genuinely needs to construct it**, that is a second factory method, not a
second `new` — add it to the authorised factory so the invariant stays in one file.

## What is deliberately not flagged

**Tests construct freely.** A test under `tests/` may `new` a sealed class directly, because
building a specific state is the job and the factory's validation is often what the test is
working around.

**Dynamic construction** — `new $class(...)` — is skipped, because the class is not statically
known.

**Reflection and deserialisation** are a documented residual gap: `ReflectionClass::newInstance()`
and `unserialize()` both bypass the `new` operator and therefore this rule. The seal is a strong
convention enforced at the normal route, not a security boundary.

## If you believe an instance is legitimate

Ask what the factory does that this call site is skipping. If the answer is "nothing" the class
should not be sealed; if it is "validation I do not need here", that is the case the seal exists
to prevent, and the object you are building may not be one the rest of the system can trust.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
