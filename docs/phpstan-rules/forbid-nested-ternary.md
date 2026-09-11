# `phpqaci.nestedTernary` — no nested ternary expressions

**Rule**: `ForbidNestedTernaryRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A ternary whose condition, true-branch or false-branch is itself a ternary.

```php
$x = $a ? ($b ? $c : $d) : $e;
$x = ($a ? $b : $c) ? $d : $e;
$x = $a ? $b : ($c ? $d : $e);
```

## Why this is a hazard

A nested ternary asks the reader to hold a decision tree in their head with no names to hang
it on. Each branch is an expression rather than a statement, so there is nowhere to put the
word that says what it *means* — the intermediate results are anonymous.

It is also a documented PHP footgun. Ternaries were left-associative before PHP 8, which made
`$a ? $b : $c ? $d : $e` group as `($a ? $b : $c) ? $d : $e` — almost never what the author
meant. PHP 8 makes the unparenthesised form a fatal compile error rather than quietly picking
the surprising grouping, so the language itself treats this shape as a mistake. The rule
extends that judgement to the parenthesised form, which is legal and still unreadable.

The practical cost is in review and modification. A reviewer cannot check a condition they
cannot name, and the next person to add a case has to re-derive the whole tree before they can
safely touch one branch of it.

## The correct construction

**Name the intermediate result.** Almost always a one-line change, and the name is the
documentation:

```php
$discount = $isMember ? $memberRate : $standardRate;
$price    = $isSale ? $discount : $listPrice;
```

**Use `match` when you are really branching on one value.** This is the better fix whenever the
nesting exists to test the same subject repeatedly, because it makes the exhaustiveness visible:

```php
$label = match (true) {
    $score >= 90 => 'excellent',
    $score >= 70 => 'good',
    $score >= 50 => 'pass',
    default      => 'fail',
};
```

**Or extract a method**, when the tree encodes a real policy. A named method is the only one of
these forms that can be tested on its own:

```php
private function rateFor(Customer $customer): Rate
{
    if ($customer->isMember()) {
        return $customer->isLapsed() ? Rate::Lapsed : Rate::Member;
    }

    return Rate::Standard;
}
```

## What is still allowed

A single, flat ternary is untouched: `$name = $user->name ?? 'anonymous'` and
`$label = $isActive ? 'on' : 'off'` are exactly the cases the operator is good at. The rule
fires only on nesting, so there is no pressure to rewrite a simple conditional as an `if`.

Chained `??` is not a ternary and is not flagged, though note that `?? false` and `?? ''` have
rules of their own ([`phpqaci.nullCoalescingFalse`](forbid-null-coalescing-false.md),
[`phpqaci.nullCoalescingEmptyString`](forbid-null-coalescing-empty-string.md)).

## If you believe an instance is legitimate

Extract the inner ternary to a variable directly above. It costs one line, and there is no
case where the nested form carries information the named form loses.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
