# `phpqaci.requireExplicitDIAttribute` — every class declares whether it is a service

**Rule**: `RequireExplicitDIAttributeRule`
**Bundle**: [`rules-optional-symfony.neon`](../../rules-optional-symfony.neon) (opt in, Symfony)

This page also covers [`phpqaci.conflictingDIAttributes`](#phpqaciconflictingdiattributes),
reported by the same rule.

## What fires

A class in `src/` carrying neither `#[Autoconfigure]` (nor `#[AutoconfigureTag]`) nor
`#[Exclude]`.

```php
final readonly class InvoiceTotal          // service, or value object? nothing says
{
    public function __construct(public int $net, public int $tax)
    {
    }
}
```

## Why this is a hazard

The near-universal Symfony service configuration is a glob:

```yaml
services:
    App\:
        resource: '../src/'
```

Which means **every class under `src/` is registered as a service** — DTOs, value objects,
entities, exceptions, enums. That default is invisible: nothing in the class says it happened,
and nothing fails when it is wrong.

What follows from it:

- **The container carries classes that are not services.** Compilation processes hundreds of
  definitions that should never exist, and the compiled container grows with them.
- **Domain objects become injectable.** A value object in the container can be type-hinted into
  a constructor, and Symfony will happily supply one — built with no data, because the container
  has no idea what its arguments should be. The result is an "empty" domain object handed out as
  a dependency, which is a bug that looks like a wiring success.
- **Nothing records intent.** A reader cannot tell whether a class is meant to be a service, and
  neither can the next person deciding whether it is safe to inject.

The rule's value is in making the declaration explicit rather than in either answer: once every
class says which it is, the glob stops being a silent default and becomes a checked one.

## The correct construction

**For a service** — something stateless the container should build and share:

```php
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure]
final readonly class InvoiceCalculator
{
    public function __construct(private TaxRateProvider $rates)
    {
    }
}
```

**For anything that is not a service** — DTOs, entities, value objects, exceptions, enums:

```php
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class InvoiceTotal
{
    public function __construct(public int $net, public int $tax)
    {
    }
}
```

The test to apply: *would it ever make sense for the container to construct this with no
arguments of its own choosing?* If constructing it requires knowing specific data, it is not a
service.

## `phpqaci.conflictingDIAttributes`

The second identifier this rule reports: a class carrying **both** a service attribute
(`#[Autoconfigure]` or `#[AutoconfigureTag]`) and `#[Exclude]`.

```php
#[Autoconfigure]
#[Exclude]                    // which is it?
final class Thing { ... }
```

This is not a style question — the two attributes are contradictory instructions to the
container, and which wins is a detail of resolution order rather than something the code states.
Usually it means an `#[Exclude]` was added in bulk to satisfy the first rule without noticing the
class was already registered. Delete whichever is wrong; if that is genuinely unclear, the class
is doing two jobs and should be split.

## What is deliberately not flagged

The rule is Symfony-tier and opt-in, because it only makes sense against the glob-registration
pattern. A project wiring its services explicitly in YAML has no such default and needs no such
declaration.

## If you believe an instance is legitimate

A class that is neither a service nor a domain object usually turns out to be a static utility,
which is better expressed as a service with real dependencies or as functions on a value object.
Adding `#[Exclude]` to make the error go away is the correct fix when the class really is not a
service — that is the construction the rule wants, not a workaround for it.
