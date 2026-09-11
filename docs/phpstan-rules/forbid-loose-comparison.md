# `phpqaci.looseComparison` — no `==` / `!=`

**Rule**: `ForbidLooseComparisonRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

Any use of `==` or `!=`.

```php
if ($status == 'active') { ... }
if ($count != 0) { ... }
```

## Why this is a hazard

`==` compares values *after* coercing them to a common type, and PHP's coercion table produces
results nobody predicts from reading the line:

```php
"0"  == false     // true
""   == null      // true
"1"  == true      // true
"abc" == 0        // false in PHP 8, true before it
```

That last line is the important one. PHP 8 changed string-to-number comparison, so the same
expression means different things on different majors. Code written against the old behaviour
keeps compiling and silently changes what it decides.

The failure mode is what makes it worth a rule rather than a preference. A loose comparison
does not error, does not warn, and returns a perfectly ordinary boolean — so an input like the
string `"0"` takes the false branch and every test written with a real `false` still passes.
The bug reaches production attached to one specific shape of data.

## The correct construction

**Use `===` and `!==`**, which compare value and type and never coerce:

```php
if ($status === 'active') { ... }
if ($count !== 0) { ... }
```

Where the two sides genuinely have different types, that is the finding — convert deliberately,
at a point where you can say what should happen if the conversion is impossible:

```php
$limit = filter_var($raw, FILTER_VALIDATE_INT);
if (false === $limit) {
    throw new InvalidArgumentException('limit must be an integer');
}

if ($limit === 0) { ... }
```

For a nullable value, test the null case explicitly rather than leaning on coercion to fold it
in with the empty case — they are different states and usually want different handling.

## What is still allowed

`<`, `>`, `<=`, `>=` and `<=>` are untouched; they are not the coercion hazard this rule is
about. `===` on objects is identity, not equality, which is the intended meaning here — if you
want structural equality, write an `equals()` method and call it.

Related: [`phpqaci.emptyLanguageConstruct`](forbid-empty-language-construct.md) covers
`empty()`, which collapses the same set of distinct values by the same mechanism.

## If you believe an instance is legitimate

There is essentially always a `===` that says what you meant, once you decide which types are
actually in play. If you cannot decide, that uncertainty is the defect the rule found — the
line is making a decision on data whose type nobody has pinned down.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
