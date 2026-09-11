# `phpqaci.mockFinalClass` — mock an interface, never a final class

**Rule**: `ForbidMockingFinalClassRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

`createMock()` or `createStub()` on a **final class the analysed project owns**.

```php
$gateway = $this->createMock(StripeGateway::class);   // final, and ours
```

Third-party final classes are deliberately skipped — you cannot add an interface to code you do
not control. "Third-party" is decided by
[`VendoredCodeDetector`](forbid-unanchored-vendor-substring-check.md) against the analysed
project's own vendor directory, not a bare `/vendor/` substring, because a package developed in
place inside a consumer's `vendor/` would otherwise misclassify its own source.

## Why this is a hazard

It does not work. PHPUnit builds a mock by generating a subclass, and a final class cannot be
subclassed — PHPUnit 13 throws `ClassIsFinalException`. So the immediate cost is a test that
fails on upgrade rather than a test that lies.

The design signal matters more. Needing to mock a concrete class means the unit under test
depends on an implementation rather than on a contract. Every such mock hard-codes the shape of
a specific class into the test, so a refactor of that class breaks tests belonging to a
different unit — the tests stop measuring the thing they name.

The workarounds are worse than the rule. Removing `final` to make mocking possible weakens a
real constraint for a test's convenience, and extensions that rewrite bytecode to mock final
classes make the test suite depend on the runtime being patched.

## The correct construction

**Extract an interface and depend on it.** The concrete class implements it, services type-hint
it, tests mock it:

```php
interface PaymentGateway
{
    public function charge(Money $amount, CardToken $card): ChargeResult;
}

final readonly class StripeGateway implements PaymentGateway { ... }

// In the service
public function __construct(private PaymentGateway $gateway)
{
}

// In the test
$gateway = $this->createMock(PaymentGateway::class);
```

This is dependency inversion: both the service and the concrete class depend on the abstraction,
and the test measures the service against the contract rather than against Stripe.

**Or use a real instance**, which is often better than a mock for a value-like or pure class.
If the class has no I/O, constructing it is cheaper and truer than describing it.

**Or write a fake** — a small hand-written implementation of the interface with real behaviour —
where the same collaborator is stubbed across many tests. A fake is one place to change when
the contract moves, rather than thirty `willReturn()` chains.

## What is still allowed

Mocking interfaces and non-final classes is untouched. Third-party final classes are skipped,
as above — though the better answer there is usually a thin adapter interface of your own, so
your tests describe what *you* need rather than what the library happens to expose.

## If you believe an instance is legitimate

The class is final for a reason and the test wants to bypass it. That tension is the finding: if
the unit genuinely needs to vary that collaborator, the collaborator is a contract and should
have an interface. If it does not, use the real object.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
