# `phpqaci.magicStringAssertion` — no magic-string assertion where an enum belongs

**Rule**: `ForbidMagicStringAssertionRule`
**Bundle**: none — experimental, see
[the experimental rules note](../tools/phpstan.md#experimental-rules-not-in-any-bundle)

## What fires

A test assertion pinning an **identifier-like string literal** against a value PHPStan types as
a general `string`.

```php
self::assertSame('desk', $portal->getProductType());     // getProductType(): string
self::assertSame('active', $subscription->status());     // status(): string
```

"Identifier-like" means a single token with no whitespace or punctuation — `'desk'`,
`'active'`, `'GET'`. That narrowing is what keeps assertions about rendered HTML, messages and
formatted text out of it.

## Why this is a hazard

The assertion is the only thing holding the value to its set. `getProductType(): string` can
return anything at all; the test says it should be `'desk'` here, and nothing anywhere says what
the other legal values are or that this is the complete list. A typo in production returns a
string the type system is perfectly happy with.

So the test is doing the type system's job, and doing it worse — once per call site, after the
fact, and only for the paths a test covers.

**Why PHPStan's own `alreadyNarrowedType` does not cover this.** That rule fires when an
assertion is provably always true — which it becomes *after* you model the value as an enum.
It is a lagging proof, silent for exactly as long as the value is still a loose `string`, which
is the moment you need to be told. This rule fires on the smell instead, before the enum exists.

The two are complementary: this one catches the cause, the built-in confirms the cure. Model the
value as an enum and this rule goes quiet; keep the now-tautological assertion and
`alreadyNarrowedType` tells you to delete it.

## The correct construction

There are two fixes, and which applies depends on what the string is.

**A domain value — a closed set such as a status, type or action.** Model it, and the type
guarantees what the assertion was checking:

```php
enum ProductType: string
{
    case Desk = 'desk';
    case Sales = 'sales';
}

// production
public function getProductType(): ProductType

// test
self::assertSame(ProductType::Desk, $portal->getProductType());
```

The assertion is now nearly redundant, which is the point — the illegal states became
unrepresentable rather than merely untested.

**Test data — a fixture id, key or sample value.** It is not a domain concept, it is a value
this test made up, and the defect is that it is written twice:

```php
private const string CUSTOMER_ID = 'cust-1';

public function testItFindsTheCustomer(): void
{
    $this->repository->save(new Customer(self::CUSTOMER_ID));

    self::assertSame(self::CUSTOMER_ID, $this->finder->latest()->id);
}
```

Defined once, referenced from both the arrange step and the assertion, so the two cannot drift.

## What is deliberately not flagged

Assertions against non-identifier strings — anything with whitespace or punctuation — are never
reported: rendered output, exception messages and formatted text are legitimately compared to
literals.

Assertions against a value PHPStan already types as an enum or a constant are not reported
either; there is no magic string left to pin.

## Status

Experimental and in **no bundle**: it is not loaded by `rules-default.neon` or either optional
tier, so nothing runs it unless a project registers it deliberately. It is opinionated about
test style, and the judgement of which of the two fixes applies is genuinely a person's.

## If you believe an instance is legitimate

An identifier-like literal that is neither a domain value nor test data is worth a second look —
it is usually a protocol constant (`'GET'`, `'UTF-8'`) that belongs in a constant somewhere, for
the same define-it-once reason.

Since the rule is in no bundle, the simplest response to disagreeing with it is not to register
it.
