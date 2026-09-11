# `phpqaci.deprecatedSerializable` — no `Serializable`

**Rule**: `ForbidDeprecatedSerializableRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A class declaring `implements Serializable`.

```php
final class Token implements Serializable
{
    public function serialize(): string { ... }
    public function unserialize(string $data): void { ... }
}
```

## Why this is a hazard

`Serializable` was deprecated in PHP 8.1. Implementing it without also declaring
`__serialize()` and `__unserialize()` raises a deprecation notice on every class definition,
and the interface is slated for removal.

The design problem behind the deprecation is the reason it is worth changing rather than
silencing. `Serializable::unserialize()` reconstructs the object *from a string*, inside the
object, which means the class has to do its own parsing and cannot use its constructor. Nested
objects are resolved eagerly and the format is opaque, so a payload's structure is only known
to the code that wrote it — and the interface interacts badly with inheritance, because a
subclass cannot extend a parent's serialised form without re-implementing the whole string.

The replacement pair avoids all of that by exchanging an **array** rather than a string: PHP
handles the encoding, references and nesting, and the class only has to say which state matters.

## The correct construction

**Implement `__serialize()` and `__unserialize()`**, and drop the interface — no interface is
needed, PHP looks for the magic methods:

```php
final class Token
{
    public function __construct(
        private string $value,
        private DateTimeImmutable $expiresAt,
    ) {
    }

    /** @return array{value: string, expiresAt: DateTimeImmutable} */
    public function __serialize(): array
    {
        return ['value' => $this->value, 'expiresAt' => $this->expiresAt];
    }

    /** @param array{value: string, expiresAt: DateTimeImmutable} $data */
    public function __unserialize(array $data): void
    {
        $this->value     = $data['value'];
        $this->expiresAt = $data['expiresAt'];
    }
}
```

Use a string key array rather than a positional one: it survives adding a field, and it is
readable in a dump.

**Or stop serialising the object at all**, which is the better answer whenever the serialised
form crosses a process, a queue or a storage boundary. PHP's native serialisation format ties
stored data to your class layout, so a refactor breaks old payloads. An explicit DTO plus JSON
gives you a format you can version:

```php
public function toArray(): array
{
    return ['value' => $this->value, 'expires_at' => $this->expiresAt->format(DATE_ATOM)];
}
```

## What is still allowed

`JsonSerializable` is a different interface for a different job and is untouched.
`__serialize()`/`__unserialize()` on their own are the recommended construction. Implementing
`Serializable` is only flagged where your own code declares it.

## If you believe an instance is legitimate

The one real constraint is stored payloads written by the old implementation. Keep both for one
release — a class may declare the magic methods *and* the legacy interface, and PHP prefers the
magic methods — then migrate the data and remove the interface. That transitional state is an
`ignoreErrors` entry with a path and a note saying which release removes it.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
