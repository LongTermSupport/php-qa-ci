# Defence Before Fix — Net and Filter

This document is the single source of truth for the **Defence Before Fix** philosophy.
The `defence-before-fix` skill orchestrates the workflow; this doc states *why* it is
shaped the way it is, and the rules that keep a "green" honest.

Reference (the specification for the method): https://defence-before-fix.github.io/
Agent prompt, vendored with provenance (read once before fixing any defect):
[remote-docs/defence-before-fix.github.io/defence-before-fix-project-prompt.md](../remote-docs/defence-before-fix.github.io/defence-before-fix-project-prompt.md)
(refresh with `.claude/hooks-daemon/bin/hooks-daemon remote-docs refresh --all`; the
specification governs where the two differ).
Original publication (the article): https://ltscommerce.dev/articles/defence-before-fix-static-analysis

The versions this package is audited against are declared in `composer.json`
`extra.defence-before-fix` and pinned by `tests/Small/DefenceBeforeFixDeclarationTest.php`.

## The procedure: six clauses, in order

This is the method specification's section 3 as it applies here. Clause numbers are the
specification's. A remediation Conforms only when all six are followed for that defect;
the skill (`.claude/skills/defence-before-fix/SKILL.md`) is a shim onto this section.

**Before clause 3.1: do not fix the defect yet.** The instance is evidence of a class.

### 3.1 Attribute the defect to a class

1. Name the **Class** as a pattern and write a one-sentence **Hazard** statement (the harm;
   it need not be a failure — error hiding and "merely sloppy" both count).
2. **Search independently by at least two techniques** chosen before the rule runs, until the
   last technique finds nothing the earlier ones missed. Independent means they would miss
   different things: a text search for the token and a reading of the code paths that consume
   the value are two techniques; two spellings of one grep are one. The rule is never one of
   the two, and a clean run of the rule is not evidence of saturation.
3. **Bound the class both ways.** A rule that catches only the originating instance is too
   narrow and MUST be widened unless the search found nothing wider, in which case record that
   and name the next wider rule with the reason it was not built. A rule that matches code
   without the hazard is too broad and MUST be narrowed: one false report is one too many.
4. **Pin the behaviour**: record whether a Runner check (a test) pins the reported behaviour,
   or why not. A class drawn at the mechanism with the behaviour unpinned defends the instance
   and not the report.
5. **Record** all of the above (class, hazard sentence, the two techniques and what they
   found, next wider rule and why not, runner-check status) before starting 3.2. In this
   repository the record is the plan folder if there is one, else the rule's remediation page.

### 3.2 Build the net in a Detector

Express the class as a **Rule in a Detector**: a tool that reads code rather than executing
it. **A test MUST NOT serve as the Detector.** Choose the engine in this order:

- **PHPArkitect** for structural conventions (naming, namespace layering, dependency
  direction) — see the README "Where does a rule belong". Never enforce one convention in
  both engines.
- **PHPStan** for method-level or semantic patterns (the `php-qa-ci_phpstan-rule-creator`
  agent authors these).
- **A bespoke Detector** — a program of the project's own that reads the artefact and reports
  matches, run through `bin/qa` like any other lane — where the pattern lives outside PHP
  (configuration, XML, YAML, workflow templates). This is the shape of the always-on lanes
  under `src/` (see `docs/phpstan-rules/README.md` "Pipeline lanes"), and a last resort
  rather than a default.

If no extensible Detector exists and a bespoke one is impractical, the class is a **toolchain
gap**: record it as such (with the reason), then fix the defect conventionally with its
reproduction test. If the Detector exists but the toolchain lacks a required mechanism, build
the rule anyway and record the mechanism gap in `composer.json` `known-gaps`.

### 3.3 Prove the net: make the rule fire

- **Red before green.** The rule MUST be seen to fire on the originating defect. If the
  pattern is absent (already fixed, or defending pre-emptively), prove it on **Fixture** code
  and keep the fixture as the rule's own test.
- **The red run survives as its own commit.** Commit the Defence with the originating instance
  still present; commit the fix after it. Both commits must stay individually reachable
  (this repository merges with `--no-ff`, never squash, for exactly this reason).
- **Narrowing is a sentence.** An exclusion is a Narrowing only when you can write down why
  the hazard cannot arise in the excluded code; record that sentence. If you cannot, or are
  unsure, it is a **Suppression** and belongs to the Owner. Never narrow because the count is
  high: the test is the hazard, never the count. Every narrowing must be enumerable alongside
  the project's exceptions (`bin/rules`).
- **Confirm the rule was loaded** before trusting any green run: `bin/rules` lists it, or it
  fires on a fixture in the same run. `bin/phpstan-rule <identifier> <path>` is the
  single-rule harness for PHPStan rules.
- A rule about how code is written will match its own source; that match is not an instance.
- Rules are software: build them test-first where the Detector makes that practical.

### 3.4 Sweep the whole codebase, then fix every instance

- Run the rule everywhere the pattern can occur — every language and component, not just the
  one that reported — and **record the instance count before fixing anything**. Absent a
  recorded project decision, sweep all first-party source and exclude generated and vendored
  code, and record that as the decision. The rule's own fixtures are never instances.
- **Corroborate the count** by the independent search from 3.1. If the search finds instances
  the rule missed, the rule is too narrow: widen it. The search is the authority.
- **Fix every instance.** Each fix addresses the hazard, never the rule: no change that turns
  the rule green with the failure mode intact, no suppression at the call site. A default is
  usually the hazard in another form; it is a fix only where absence is a legitimate state
  whose meaning the code defines.
- Where one change is applied across many instances, examine each; record which were examined
  individually and which received the change by pattern, and sample the latter.
- There is no count at which you stop. Stop only when the next instance needs a decision you
  lack the authority to make (section 4). **Wire, don't delete**: see below.

### 3.5 Enforce permanently, and block

- The rule is in the checks the project uses to accept changes, it **fails rather than warns**,
  and every suppression it carries is covered by a recorded decision (`qaConfig/phpstan.neon`
  `ignoreErrors` with a justification the `phpstanIgnoreJustification` lane accepts).
- **Demonstrate through the entry point**: run the pipeline over everything the rule covers and
  see it reported there. Running the Detector directly proves the rule, not the Defence. For a
  bundled rule the demonstrating project is any project the toolchain is installed into, this
  repository included. Which command to run is in
  [prepush-verification.md](prepush-verification.md) and is not repeated here.
- Removing or disabling a rule, or adding a suppression, is a recorded Owner decision, never a
  silent edit.

### 3.6 Terse message, stable identifier, real documentation

- The failure message carries what was detected, where, and a **stable identifier**
  (`phpqaci.<name>` for bundled rules and lanes; the project's own prefix for project rules).
  The identifier must keep resolving for as long as any released version can emit it.
- The identifier resolves, offline, to remediation documentation shipped with the project
  that states **what the rule is about, why it exists, and how to fix a violation correctly**
  using the project's preferred construction. "Pattern X detected" teaches nothing.
- Rule and documentation are one versioned artefact: in this repository that is the identifier
  index `docs/phpstan-rules/README.md` plus a page under `docs/phpstan-rules/` or
  `docs/tools/`, resolved by `bin/rule-doc <identifier>` and guarded by
  `tests/Small/PHPStan/RuleDocumentationTest.php`.

**Only then** fix the original defect in the normal way, with a test that reproduces it
(section 6 of the specification: the test pins the instance, the rule catches the class).

## Authority: what an agent may and may not decide

The Practitioner decides how the Defence is built; the **Owner** (always a human) decides what
the codebase is permitted to keep.

- **Yours to decide**: the class and its bounds, the Detector and the rule, how it is proven
  and which fixtures, the correct behaviour at each instance, the message and the docs.
- **Owner only**: adopting a Baseline in whole or part; suppressing an instance; removing or
  disabling a rule; accepting a known unfixed instance; deciding a defensible class will not be
  defended; leaving the next wider rule unbuilt for any reason other than an absent hazard.
- **The standing answer is no.** Suppressions and Baselines are Exceptions, not techniques with
  a threshold. An Exception exists only where a human has discussed, agreed and documented it
  for this project. Doubt is Suppression: if you are not confident excluded code is free of
  the hazard, refer it up.
- **The count never escalates.** Five hundred instances with no Exception needed is five
  hundred instances to fix. Under agent-driven work, fixing is cheap; reaching for an Exception
  is almost always a shortcut.
- **Do not stall.** Finish everything within your authority, report the blocked decision with
  the instance count and what fixing would cost, and leave the rule unmerged rather than merged
  weakened. A rule MAY merge at full width with one matched case recorded as a known instance
  awaiting the Owner; a remediation MAY span several changes provided the rule is not Blocking
  until every instance is fixed.
- Do not add or change the conformance declaration in `composer.json` on your own authority;
  report what you found.

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
- `bin/rules [project-root] [--json]` lists every defence active in a project — its resolved
  `phpstan.neon` rules, the always-on pipeline lanes, and the project record — WITHOUT running
  PHPStan, so an agent arriving cold can learn the standards without violating them first.

## The net has to be cast over itself

The bundled rules reach a consumer through the PHPStan extension installer, which never reads the
root package. Left alone, php-qa-ci is the one project in which its own rules never run, and a
clean self-check is believed because nobody expects a clean run to have run nothing. That is how an
unanchored `vendor/` check lived in a rule file undetected. `qaConfig/phpstan.neon` therefore
includes every bundled rule set by hand, and `tests/Small/SelfCheckRunsBundledRulesTest.php`
fails the build if one is dropped.

A new rule is not finished when it passes its own test. It is finished when somebody who has only
its identifier can find out what to do.

## The claim is declared where it can be checked

`composer.json` carries `extra.defence-before-fix`, naming the version of the Defence Before Fix
method specification and of its toolchain specification this package implements, with a
`known-gaps` list. A gap the package learns of, from its own self-checks or from a practitioner's
report, is recorded there against the clause it fails, and the package does not claim conformance
whilst that list is non-empty. The key is machine-readable so a consumer checks the claim against
the installed artefact rather than against a sentence in a README.

The declaration carries two levels, graded separately, because they have different readers:

- The top-level `method`, `toolchain` and `known-gaps` describe the **artefact**: what a
  consuming project gets when it installs this package with nothing else built around it.
- The `project` object carries the same three keys for **this repository as a project**
  following the method with the toolchain it ships. A gap can hold at one level and not the
  other; most hold at both, because the project runs the artefact it ships.

Every entry in either `known-gaps` list opens with the document and clause it fails, in the shape
`toolchain 4.1: ...`, `detector 6.2: ...` or `method 3.6: ...`, followed by one sentence stating
the gap. `tests/Small/DefenceBeforeFixDeclarationTest.php` guards the shape: both levels present,
the versions this package is audited against, and every gap naming its clause.

## Cross-Reference

- Identifier index: `docs/phpstan-rules/README.md` (start here when a rule fires).
- Workflow skill: `.claude/skills/defence-before-fix/SKILL.md` (model-invoked shim onto
  "The procedure" above; forces the vendored agent prompt and this document to be read first).
- Rule authoring: `qaConfig/PHPStan/CLAUDE.md` (deployed into each project) and the
  `php-qa-ci_phpstan-rule-creator` agent.
- Project root signpost: the auto-generated `<phpqaci>...</phpqaci>` block in the project
  root `CLAUDE.md` carries a terse pointer back here (written on every
  `composer install`/`update`).
