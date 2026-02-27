---
name: defence-before-fix
description: |
  Defence Before Fix workflow for preventing bug classes through static analysis.
  Implements the ratcheting pattern: analyse bug -> create PHPStan rule -> TDD -> fix.

  Use when:
  - A bug has been found and you want to prevent the entire bug class
  - "defence before fix", "create a PHPStan rule for this bug"
  - "detect this pattern with static analysis", "ratchet this bug class"
  - User wants to encode institutional knowledge as a PHPStan rule

  This skill orchestrates 4 phases:
  1. ANALYSE - Understand the bug pattern
  2. DETECT - Create a PHPStan rule to catch the pattern
  3. TDD - Write failing tests reproducing specific bugs
  4. FIX - Implement fixes, verify rules and tests pass

  Reference: https://ltscommerce.dev/articles/defence-before-fix-static-analysis
allowed-tools: Task, Skill
---

# Defence Before Fix Skill

Workflow skill that implements the "Defence Before Fix" strategy: each production
incident yields both a fix AND a defensive PHPStan rule. Quality only turns one way.

Reference: https://ltscommerce.dev/articles/defence-before-fix-static-analysis

## Core Principle

One PHPStan rule prevents an entire class of bugs across all future commits and
untested code paths. Tests only catch specific manifestations. Rules catch the pattern.

## Prerequisites

Before starting, verify PHPStan rule infrastructure exists:
- `qaConfig/PHPStan/Rules/` directory exists
- `qaConfig/PHPStan/CLAUDE.md` documentation exists
- `QaConfig\` PSR-4 entry in `composer.json` autoload-dev
- `qaConfig/phpstan.neon` has a `rules:` section

If any are missing, run deployment:
```bash
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

## Phase Detection

Parse the user's request to determine which phase to start at:

| User Says | Start Phase | Notes |
|---|---|---|
| "defence before fix [bug description]" | Phase 1 | Full workflow |
| "create a PHPStan rule for [pattern]" | Phase 2 | Pattern already understood |
| "detect [pattern] with static analysis" | Phase 2 | Pattern already understood |
| "ratchet this bug class" | Phase 2 | Pattern already analysed |
| "reproduce this bug with tests" | Phase 3 | Rule already created |
| "fix [bug] and verify" | Phase 4 | Tests already written |

## Phase 1: ANALYSE

**Goal:** Understand the bug pattern well enough to write a PHPStan rule for it.

This phase is primarily manual/guided. Help the user by:

1. **Identify the bug class** — Not just the specific bug, but the general pattern.
   Ask: "What category of error is this? Silent default? Missing validation?
   Magic string? Type coercion? Empty catch?"

2. **Find all instances** — Search the codebase for the same pattern:
   ```
   Use Grep tool to search for the pattern across the codebase
   ```

3. **Document the pattern** — Describe:
   - What the buggy code looks like (AST-level: what node types are involved?)
   - What the correct code looks like
   - Why the pattern is dangerous
   - How many instances exist

4. **Assess feasibility** — Can PHPStan detect this at the AST level?
   - Simple patterns (magic strings, empty catches, silent defaults): YES
   - Type-level patterns (wrong return types, missing checks): MAYBE (needs Scope)
   - Domain logic patterns (wrong business rules): NO — use tests instead

**Output:** Clear description of the pattern for Phase 2.

## Phase 2: DETECT (Static Analysis)

**Goal:** Create a PHPStan rule that catches ALL instances of the bug pattern.

### Step 1: Launch the Rule Creator Agent

```
Use Task tool:
  description: "Create PHPStan rule for [pattern]"
  subagent_type: "php-qa-ci_phpstan-rule-creator"
  prompt: |
    Create a PHPStan rule that detects the following pattern:

    PATTERN: [description from Phase 1]

    WHAT IS WRONG:
    [buggy code example]

    WHAT IS RIGHT:
    [correct code example]

    WHY IT IS DANGEROUS:
    [explanation]

    EXPECTED VIOLATIONS:
    [list of files/locations that should be flagged]

    Read qaConfig/PHPStan/CLAUDE.md and existing rules in qaConfig/PHPStan/Rules/
    for project conventions. Also read examples in
    .claude/skills/defence-before-fix/examples/ for common patterns.
```

### Step 2: Verify the Rule

After the agent creates the rule, verify it catches violations:

```
Use Skill tool:
  skill: "phpstan-runner"
```

The PHPStan output should show violations at the expected locations.

### Step 3: Document

Record which violations were found and confirm they match expectations.

**If the rule does not catch expected violations:** The AST detection logic may need
adjustment. Either re-invoke the rule creator agent with more specific guidance, or
manually edit the rule in `qaConfig/PHPStan/Rules/`.

## Phase 3: TDD (Test Reproduction)

**Goal:** Write failing tests that reproduce the specific bugs found.

This phase uses standard TDD workflow:

1. **Write failing tests** — Each test should:
   - Target a specific bug instance (not the general pattern — the rule handles that)
   - Assert the CORRECT behaviour (what should happen after the fix)
   - Currently FAIL (proving the bug exists)

2. **Run tests to confirm they fail:**
   ```
   Use Skill tool:
     skill: "phpunit-runner"
   ```

3. **Document** — Record which tests fail and what they expect.

**Key insight:** The PHPStan rule (Phase 2) catches the pattern across the codebase.
The tests (Phase 3) verify the specific fix works correctly. Both are needed — they
serve different purposes.

## Phase 4: FIX (Implementation)

**Goal:** Fix the code so tests pass and PHPStan rules pass.

1. **Implement fixes** — Make the failing tests pass.

2. **Verify PHPStan rules pass:**
   ```
   Use Skill tool:
     skill: "phpstan-runner"
   ```
   The rule violations from Phase 2 should now be resolved.

3. **Run full QA:**
   ```
   Use Skill tool:
     skill: "qa"
   ```
   Run allCS then allStatic to verify everything is clean.

4. **Done** — The bug class is now permanently prevented:
   - PHPStan rule catches the pattern in all future code
   - Tests verify the specific fix works
   - The ratchet has turned — quality only goes one way

## Integration with Plan Workflow

For complex bug fixes, create a plan (see `@CLAUDE/PlanWorkflow.md`):

```
Phase A: PHPStan Rules       <- Defence Before Fix Phase 2
Phase B: TDD                  <- Defence Before Fix Phase 3
Phase C: Fix Implementation   <- Defence Before Fix Phase 4
Phase D: Verify and Ship
```

The plan provides structure for tracking progress across phases.

## Common Patterns and Their Rules

| Bug Pattern | PHPStan Node Type | Example Rule |
|---|---|---|
| Magic strings in attributes | `Node\Attribute` | RoutePathMustUseConstantsRule |
| Silent defaults (`?? ''`) | `Node\Expr\BinaryOp\Coalesce` | ExampleSilentDefaultRule |
| Empty catch blocks | `Node\Stmt\Catch_` | ExampleEmptyCatchRule |
| Missing return value check | `Node\Expr\MethodCall` | Custom per-method |
| Constant not composed | `Node\Stmt\ClassConst` | RouteNameConstantMustComposeRule |

Example rules are in `.claude/skills/defence-before-fix/examples/`.

## When NOT to Use This Skill

- **One-off bugs** with no general pattern — just fix and test
- **Domain logic errors** that cannot be detected at AST level — use tests only
- **Configuration errors** — fix the config, no rule needed
- **Third-party library bugs** — report upstream, no rule needed

## Remember

The defence-before-fix pattern is about **preventing bug classes, not fixing individual
bugs**. One rule prevents hundreds of future bugs. That is the force multiplier.
