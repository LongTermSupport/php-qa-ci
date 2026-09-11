# `phpqaci.inlinePhpstanIgnore` — no inline `@phpstan-ignore`

**Rule**: `ForbidInlinePhpstanIgnoreRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

An inline PHPStan suppression comment in source. Test files and `qaConfig/` are skipped.

```php
/** @phpstan-ignore-next-line */
$total = $row['amount'] + $row['tax'];

$value = $config->get('key'); // @phpstan-ignore argument.type
```

## Why this is a hazard

A suppression is a decision to accept a risk. The question is not whether that is ever
justified — it is where the decision gets recorded.

Inline, it is recorded at the one place nobody looks. It is invisible in review unless the
reviewer happens to open that file at that line; it cannot be counted, listed or reported on;
and nothing ever revisits it. `-next-line` in particular keeps applying after the line beneath
it has been replaced by something else entirely, so the suppression silently transfers to code
the author never saw.

In `phpstan.neon` the same decision is a line in a config file that is read on every review of
that file, is greppable, and is enumerable — `bin/rules` lists the project record, so the set of
accepted risks is something a team can look at as a set rather than discover one file at a time.

This is why php-qa-ci makes the suppression route a **record** rather than an annotation: under
Defence Before Fix an accepted unfixed instance is an Owner decision, and a decision nobody can
enumerate is not a decision anyone is making.

## The correct construction

**Fix the underlying type problem.** Most inline ignores mark a place where the types are
genuinely unknown, and the fix states what they are:

```php
// A type guard, where the value may legitimately be several things
if (!\is_string($value)) {
    throw new InvalidArgumentException('expected a string');
}

// An array shape, where an array is really a record
/** @param array{amount: int, tax: int} $row */

// A Safe function, where the failure mode was the untyped false
$contents = \Safe\file_get_contents($path);
```

**If it is genuinely irreducible, record it** in the project's `phpstan.neon`, with the
identifier, a path, and a comment naming the hazard being accepted and its scope:

```neon
parameters:
    ignoreErrors:
        # Symfony DI attributes are referenced as strings for comparison in this rule,
        # not used at runtime; the package is deliberately not required. Scope: this file.
        -
            identifier: class.notFound
            path: ../src/PHPStan/Rules/RequireExplicitDIAttributeRule.php
```

The comment is not optional — the `phpstanIgnoreJustification` lane
([docs/tools/phpstan.md](../tools/phpstan.md#suppressing-errors)) fails the run for an entry
without one.

## What is still allowed

Test files and `qaConfig/` are skipped: a test that deliberately constructs a badly-typed value
to assert the behaviour around it is not hiding anything, and the QA config is where
suppression is configured.

`@phpstan-var`, `@phpstan-param` and the other type-*stating* annotations are not suppressions
and are untouched, though note that overriding an inferred type with `@var` to silence an error
is the same move by another name.

## If you believe an instance is legitimate

Move it to `ignoreErrors` with a justification. That is not a workaround for the rule — it is
the construction the rule exists to require, and it converts an invisible annotation into a
reviewable record.

Adding this rule's own identifier to `ignoreErrors` to re-enable inline ignores would be an
Owner decision that removes the record for every future suppression, and should be taken as
deliberately as that sounds.
