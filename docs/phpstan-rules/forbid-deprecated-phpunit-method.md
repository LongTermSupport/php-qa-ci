# `phpqaci.deprecatedPhpunitMethod` — no PHPUnit method deprecated by the installed version

**Rule**: `ForbidDeprecatedPhpunitMethodRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

A call to a method the **installed** PHPUnit marks deprecated, when the call target is a
PHPUnit class or a subclass of one.

```php
$this->expectExceptionMessage('not found');
```

The deprecated set is discovered from the installed PHPUnit rather than hard-coded, so the rule
follows PHPUnit's own deprecations across upgrades with no list to maintain here.

## Why this is a hazard

A deprecation is an announcement that the method will be removed. Left alone, the debt comes
due all at once, on the major upgrade, as a suite that no longer runs — which is the worst
moment to be making judgement calls about what a test meant.

For test-framework deprecations specifically there is a second cost. PHPUnit emits a deprecation
notice per call, so a suite with hundreds of them produces output nobody reads, and a genuinely
new warning lands in noise. Teams then turn the notices off, which removes the mechanism that
would have told them about the next one.

And some replacements are not mechanical. `expectExceptionMessage()` matches a **substring**,
which is why it is deprecated: a test asserting the message contains `not found` passes against
`user not found` and also against `template not found` — a weaker assertion than its author
believed. Deferring the change means deferring the discovery that some of those assertions were
never checking what they claimed.

## The correct construction

**For `expectExceptionMessage()`, pick the assertion you actually want**:

```php
$this->expectExceptionMessageIs('User 42 not found');            // exact
$this->expectExceptionMessageMatches('/User \d+ not found/');    // pattern
$this->expectExceptionMessageIsOrContains('not found');          // the old, loose behaviour
```

**Better still, assert against the object**, which checks class and message together:

```php
$this->expectExceptionObject(new UserNotFoundException('User 42 not found'));
```

For anything else, PHPUnit's deprecation message names the replacement, and its changelog
explains the reasoning where the swap is not one-for-one.

## What is deliberately not flagged

The rule requires **both** that the method name is in the deprecated set **and** that the call
target is a PHPUnit class or subclass. That second condition is what stops a project's own
class that happens to define a same-named method from being reported.

Instance calls (`$this->m()`), nullsafe calls (`$o?->m()`) and static calls (`self::m()`,
`Assert::m()`) are all covered. Dynamic method or class names are skipped, being unresolvable
statically.

The recognised base classes default to `TestCase` and `Assert`, and are configurable with the
`phpunitClasses` constructor argument.

## If you believe an instance is legitimate

Because the deprecated set comes from the installed PHPUnit, the finding is always current — it
cannot be stale advice about a version you are not running. The only real reason to defer is a
large mechanical migration, and that belongs in `ignoreErrors` with a path and a note saying
which upgrade closes it, rather than in a comment that outlives the excuse.

Inline `@phpstan-ignore` is itself banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case
belongs in `ignoreErrors` in the project's `phpstan.neon`, with a path and this identifier,
where it is visible and reviewable.
