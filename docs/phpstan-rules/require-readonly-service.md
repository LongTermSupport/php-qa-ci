# `phpqaci.readonlyService` — a service class must be `final readonly`

**Rule**: `RequireReadonlyServiceRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A class the rule identifies as a service that is not declared `final readonly class`.

```php
class InvoiceCalculator
{
    private ?TaxRate $rate = null;     // state that outlives the call
}
```

## Why this is a hazard

A service is normally a singleton: the container builds one and hands the same instance to
every caller. Any property it mutates is therefore state shared between callers — and, under a
long-running worker (Swoole, RoadRunner, FrankenPHP, a queue consumer), between *requests*.

The bugs that follow are the expensive kind. A value cached on the first call is reused for the
second, so behaviour depends on call order; the same input produces different output depending
on what ran before it. Tests pass because each test gets a fresh instance, and production
misbehaves because it does not. Under concurrency it becomes a data race with no lock in sight,
and the symptom — one customer seeing another's tax rate — appears far from the assignment that
caused it.

`readonly` makes the whole class impossible to get wrong: properties are assignable only in the
constructor, so there is no per-request state to leak. `final` closes the other half, since a
subclass could otherwise add mutable properties to an otherwise-immutable base.

## The correct construction

**Declare the class `final readonly` and take collaborators in the constructor**:

```php
final readonly class InvoiceCalculator
{
    public function __construct(private TaxRateProvider $rates)
    {
    }

    public function total(Invoice $invoice): Money
    {
        return $invoice->net()->plus($this->rates->for($invoice)->applyTo($invoice->net()));
    }
}
```

**If a call genuinely needs state, pass it through** — as an argument, a returned value, or a
context DTO the caller owns:

```php
public function total(Invoice $invoice, CalculationContext $context): Money
```

State that belongs to one call belongs on the stack, not on the service. This is usually the
whole fix: the mutable property was a parameter the author did not want to thread through.

**If the class holds data rather than behaviour**, it is a value object, and `final readonly` is
what you wanted anyway.

## What is deliberately not flagged

The rule auto-skips the categories where mutability is imposed from outside:

- Doctrine entities, controllers, commands, exceptions and enums
- Classes implementing `LoggerAwareInterface` or `ResetInterface` — both are *defined* by having
  a setter
- Classes extending framework base classes (`Constraint`, `TestCase`, and similar)
- Test classes, SDK and generated code
- Common framework patterns: repositories, validators, authenticators

These exemptions are why the rule is opt-in rather than always-on: the set of what counts as a
service is project-shaped, and a project with its own conventions should confirm the
classification before switching it on.

## If you believe an instance is legitimate

Ask whether the class is a service at all. A class with mutable state is usually an entity, a
DTO or a builder — and naming it as such is a better fix than exempting it, because the name is
what tells the next reader whether sharing the instance is safe.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
