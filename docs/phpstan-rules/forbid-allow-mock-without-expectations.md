# `phpqaci.forbiddenAttribute` — no `#[AllowMockObjectsWithoutExpectations]`

**Rule**: `ForbidAllowMockWithoutExpectationsRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

The `#[AllowMockObjectsWithoutExpectations]` attribute, on a test class or a test method.

```php
#[AllowMockObjectsWithoutExpectations]
final class InvoiceServiceTest extends TestCase { ... }
```

## Why this is a hazard

PHPUnit distinguishes two things that look alike. A **stub** stands in for a collaborator and
returns canned values; a **mock** additionally asserts that it was called in a particular way.
`createMock()` produces a mock, so PHPUnit warns when one is created and never given an
expectation — the test asked for verification and then verified nothing.

The attribute silences that warning wholesale, for every mock in its scope. What it removes is
the signal that a test's collaborators are not actually being checked: a reader seeing
`createMock()` reasonably infers the interaction matters, when in fact nothing about it is
asserted. Worse, it applies to mocks added *later*, so a genuinely forgotten `expects()` in next
year's test is silenced by an attribute written today for a different reason.

It is also a suppression of a diagnostic rather than a fix, which is the same shape as
[`phpqaci.inlinePhpstanIgnore`](forbid-inline-phpstan-ignore.md): the warning is real, and the
attribute makes it invisible rather than untrue.

## The correct construction

**Use `createStub()` for a collaborator you only need to return values**:

```php
$rates = $this->createStub(TaxRateProvider::class);
$rates->method('for')->willReturn(TaxRate::percent(20));
```

No expectation is implied, so nothing warns, and the reader can see at a glance that this
collaborator is scenery rather than subject.

**Use `createMock()` only where the interaction is the thing under test**, and then state the
expectation:

```php
$mailer = $this->createMock(Mailer::class);
$mailer->expects(self::once())
    ->method('send')
    ->with(self::callback(static fn (Email $e): bool => $e->to === 'a@example.com'));
```

The rule of thumb: if deleting the `expects()` line would not change whether the test can fail,
it should have been a stub.

## What is still allowed

`createStub()`, `createMock()` with expectations, and hand-written fakes are all untouched. The
rule flags only the attribute — it does not police which of stub or mock you choose, because
that is a judgement about what the test is asserting.

Related: [`phpqaci.mockFinalClass`](forbid-mocking-final-class.md) covers *what* may be mocked.

## If you believe an instance is legitimate

The usual case is a large test class where a few mocks are legitimately expectation-free.
Convert those to `createStub()` — the change is mechanical and leaves the warning available to
catch the next genuine omission, which is what the attribute would have cost you.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
