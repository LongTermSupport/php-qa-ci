# Defence Before Fix — Net and Filter

This document is the single source of truth for the **Defence Before Fix** philosophy.
The `defence-before-fix` skill orchestrates the workflow; this doc states *why* it is
shaped the way it is, and the rules that keep a "green" honest.

Reference: https://ltscommerce.dev/articles/defence-before-fix-static-analysis

## Canonical Framing

> Static analysis is the NET. TDD is the FILTER. Together they are belt and braces.

- **The NET (a PHPStan / CS rule).** Catches the whole *class* of problem at the
  structural level. Cheap, broad, and permanent: once the rule exists, the bug class
  can never silently recur on any future commit or any untested code path. This is the
  ratchet — quality only turns one way.
- **The FILTER (TDD).** Zeroes in on the *specific instance*. A failing test reproduces
  the actual defect on the real production path; making it pass proves *this* instance is
  genuinely fixed, not merely silenced.
- **Belt and braces.** The net guarantees the class is caught forever; the filter
  guarantees each instance is truly resolved. Neither alone suffices: a rule with no test
  can be satisfied by gaming the structure; a test with no rule lets the class recur
  elsewhere.

## Fix by making it work — never by deleting

When a rule goes RED, the correct GREEN comes from **making the code do its job**, not
from deleting the flagged element to silence the rule.

A classic trap is a **dead contract**: a consumer reads an optional member that no real
producer ever supplies. It can ship green as **coverage theatre** — a fixture sets the
value that production never sets, so line/branch coverage looks green on a path
production never actually takes.

```php
final class Foo
{
    // RED: $bar is consumed by a renderer, but no production caller ever passes it.
    public function __construct(public readonly ?string $bar = null) {}
}
```

- **WRONG fix:** delete `$bar` to clear the rule. This bakes in the broken / half-built
  state — it removes the symptom and the half-finished feature in one stroke, and the
  rule goes quiet for the wrong reason.
- **RIGHT fix:** WIRE `$bar` to a genuine producer and prove it with a test that
  exercises the **production** path (not a fixture that hand-feeds `$bar`). Only then is
  the contract live and the GREEN honest.
- **Deleting is correct ONLY** when the member is genuinely unwanted dead code with no
  intended producer — a deliberate scope decision, not a reflex to clear a red rule.

## Nullable members: test BOTH paths

A nullable member introduces **two** code paths — value-present and null. **Both must be
proven**: a with-value test AND a null test. A single populated-path test is exactly the
coverage-theatre trap above — it proves one branch and leaves the other unexercised,
where a dead contract can hide.

```php
// Foo above has a nullable ?string $bar — BOTH are required:
//   test 1: new Foo('x')   asserts the value-present behaviour
//   test 2: new Foo(null)  asserts the value-absent behaviour
```

**Avoid nullable unless null is a genuinely valid domain state.** If a value is always
known, type it non-nullable — fewer paths, and no false "optional" that can rot into a
dead contract. Reserve nullable for states that are legitimately absent.

## Does TDD apply? By issue type

TDD applies **where the issue type supports it**. The dividing line is simple: *is there
behaviour to assert?*

| Issue type                                 | Static rule (NET)         | TDD (FILTER)              | Why                                                                    |
| ------------------------------------------ | ------------------------- | ------------------------- | ---------------------------------------------------------------------- |
| Pure coding-standards / style / formatting | yes — the rule IS the fix | no — nothing to assert    | No runtime behaviour to assert; the rule both defines and enforces it. |
| Behaviour / procedure / contract defect    | yes — catches the class   | yes — reproduce & prove   | Real behaviour exists; write a failing test, then fix to green.        |
| Dead-contract / coverage-theatre           | yes — catches the class   | yes — via PRODUCTION path | Test must drive the real producer, not a fixture-fed value.            |
| Nullable member                            | yes — catches the class   | yes — BOTH paths asserted | Two code paths exist (value-present and null); prove each.             |

**Rule of thumb:** if you can write an assertion about behaviour that would fail before
the fix and pass after, TDD applies — use it. If the rule is purely structural/stylistic
with no behaviour to assert, the static rule alone is the complete defence.

## The Ratchet in Practice

A worked illustration of the philosophy (shape, not specifics): a tightened static-analysis
ratchet — e.g. bumping the bundled analyser to a stricter version — surfaces a batch of
pre-existing latent errors that the looser net never caught. The discipline is to **fix
every surfaced instance at root cause and never suppress**:

- No `@phpstan-ignore`, no baseline entries, no `@var` forcing, no cast-to-silence.
- Each error is a real defect the stricter net just made visible; resolve the underlying
  type/logic issue so the code is genuinely correct.
- The result is a permanent gain: the net is now stricter for all future commits, and the
  backlog it exposed is gone rather than papered over.

This is the same net-and-filter principle applied at the tooling level: tightening the net
is only worthwhile if every instance it catches is honestly fixed.

## The net has to explain itself

A rule that blocks a build without saying how to fix the code is a ratchet that only turns.
The explanation has to be reachable **from the string PHPStan actually printed**, which is the
identifier (`phpqaci.nullCoalescingFalse`) and never the class name.

- `docs/phpstan-rules/README.md` is the identifier index. It is keyed on the identifier, covers
  every rule this package can report, and lives in the installed package so the lookup works
  offline and at the version actually installed.
- `tests/Small/PHPStan/RuleDocumentationTest.php` is the defence over that: it fails the build if
  any rule references remediation documentation that does not exist, or ships an identifier the
  index omits. Both had happened before the guard existed, which is the point — neither is visible
  from inside a review of the rule itself.
- `bin/rule-doc <identifier>` is the index as a command: the one string a failure prints resolves
  to the rule's documentation, offline. `bin/phpstan-rule <identifier> <path>` is the single-rule
  harness: it runs one path under the project's own config and says whether that rule fired. Use
  the harness to prove a new rule sees its target before trusting a green full run; a green run
  proves nothing unless the rule was loaded and looked.

## The net has to be cast over itself

The bundled rules reach a consumer through the PHPStan extension installer, which never reads the
root package. Left alone, php-qa-ci is the one project in which its own rules never run, and a
clean self-check is believed because nobody expects a clean run to have run nothing. That is how an
unanchored `vendor/` check lived in a rule file undetected. `qaConfig/phpstan.neon` therefore
includes every bundled rule set by hand, and `tests/Small/SelfCheckRunsBundledRulesTest.php`
fails the build if one is dropped.

A new rule is not finished when it passes its own test. It is finished when somebody who has only
its identifier can find out what to do.

## Cross-Reference

- Identifier index: `docs/phpstan-rules/README.md` (start here when a rule fires).
- Workflow skill: `.claude/skills/defence-before-fix/SKILL.md` (model-invoked; the
  4-phase ANALYSE → DETECT → TDD → FIX ratchet).
- Rule authoring: `qaConfig/PHPStan/CLAUDE.md` (deployed into each project) and the
  `php-qa-ci_phpstan-rule-creator` agent.
- Project root signpost: the auto-generated `<phpqaci>...</phpqaci>` block in the project
  root `CLAUDE.md` carries a terse pointer back here (written on every
  `composer install`/`update`).
