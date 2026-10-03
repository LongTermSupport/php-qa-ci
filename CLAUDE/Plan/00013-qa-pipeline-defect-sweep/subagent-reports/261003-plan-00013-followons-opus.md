# Plan 00013 follow-ons: Task 3.1 (X1) and Task 3.2 (N5)

## Task 3.1 — PHPArkitect fixture harness (done)

### What ships

- `bin/arkitect-rule <because> <path>`. It is a thin PHP entry point; the logic is
  `LTS\PHPQA\Arkitect\ArkitectRuleProbe`.

- The probe resolves the entry config `phparkitect.php` through the lane's cascade.
  It writes `var/qa/arkitect-rule/probe-config.php`, a generated arkitect config
  (`ProbeConfigRenderer`) that:

  - loads the entry config into a scratch `Arkitect\CLI\Config`,
  - collects every rule through the public `getClassSetRules()` and `getRules()`, and
  - re-adds all of them against `ClassSet::fromDir(<probed dir>)`.

  An entry config that registers no rule throws, so the result is exit 2 and never a
  miss.

- The phar run uses the lane's own inputs: the same phar, `--autoload` and
  `--skip-baseline`, `--format=json`, and the same environment. The environment now
  comes from one builder, `LTS\PHPQA\Pipeline\Lane\PhpArkitect\ArkitectEnvironment`,
  which the lane also uses, so the two cannot drift. `withArkitectExcludedPaths` is not
  applied to a probed path.

- A rule is selected by matching its `because` text against the violation messages that
  `ArkitectJsonParser` already reads. When the probed path is a file, only the classes
  it declares count (`ClassFileLocator` over the file's directory).

- Exit codes are the same as `bin/phpstan-rule`: 0 the rule did not fire, 1 it fired,
  2 no verdict (usage error, missing path, crash, unreadable output, or no rule
  registered).

### Design decisions an Owner may want to revisit

1. **No identifiers for arkitect rules.** The plan's sizing note called rule identity "a
   public-API decision that wants the Owner's view". The harness avoids it: the
   `because` clause is the name. That keeps Plan 00010 Decision 5 intact, because
   nothing is parsed into an identifier. The `known-gaps` text says the lane "has no
   single-file run". That is still true of the lane, though `bin/arkitect-rule` now runs
   one rule over a single file or directory. Rewording that declaration is the Owner's
   call, so it was not edited.
2. **No lane or `-t` token.** The proof stays permanent as the project's own PHPUnit
   test, which runs the command on each fixture and asserts the exit code; the
   `phpunit` lane runs it. A dedicated lane would need a registry mapping fixtures to
   rules, which is a new configuration format and so public API. If the Owner wants a
   lane, it is mechanical on top of `ArkitectRuleProbe::probe()`.
3. **A miss is ambiguous.** PHPArkitect exposes no getter for a rule's `because`, so a
   mistyped clause and a silent rule look the same. The documented proof is therefore
   a pair: the rule fires on the violating fixture and stays silent on the conforming
   one. The alternative was reflection on `Arkitect\Rules\ArchRule::$because`, a
   private property in a package php-qa-ci does not own. It was rejected for the same
   reason as Decision 5.
4. **A fixture must declare the namespace the rule looks at.** Such a fixture breaks
   PSR-4, so `docs/tools/phpArkitect.md` tells projects to list the fixture directory in
   `psr4Validate`'s ignore list. This package already ignores `tests/assets`.

### Fixtures and tests

- `tests/assets/arkitect/ruleProbe/` is a project shaped like the shipped template: a
  hard-coded `src/` class set, the default tier extended through the environment, and
  one bespoke rule. None of its rules has an instance in its `src/`.
  - `tests/Fixtures/Arkitect/Violating/` holds one subject per rule: the seven
    default-tier rules and the bespoke one.
  - `tests/Fixtures/Arkitect/Conforming/` holds the matching conforming subjects.
- `tests/Large/Arkitect/ArkitectRuleProbeTest.php` checks:
  - the zero-instance premise,
  - that each rule fires on its violating subject and stays silent on its conforming
    one,
  - a directory probe,
  - exit 2 for an entry config that registers no rule, and
  - the real `bin/arkitect-rule` exit codes 1, 0, 2 and 2.
- The Small tests are `tests/Small/Arkitect/{ArkitectRuleProbeTest,ProbeConfigRendererTest}.php`
  and `tests/Small/Pipeline/Lane/PhpArkitect/ArkitectEnvironmentTest.php`.

### A defect found on the way, defended first

`bin/` is ignored wholesale (`/bin/*`) and each shipped entry is re-included by name.
`ComposerBinManifestTest` checked only that a declared entry exists on disk, which the
working tree satisfied, so the new bin would never have been committed. The test now
also asks `git check-ignore` about every declared entry. The red commit is f6e4789; it
also carries the `composer.json` declaration, the instance. The fix is the `.gitignore`
re-include in 38961c0.

### Commits

| Commit  | What                                                                       |
| ------- | -------------------------------------------------------------------------- |
| 525e01c | red: probe tests and the fixture project                                   |
| f6e4789 | red: the bin-ignored detector, with `composer.json` declaring the bin      |
| 38961c0 | the probe, the bin, the lane's shared environment, docs, CHANGELOG `Added` |
| 2af2de1 | the consumer `CLAUDE.md` block names `vendor/bin/arkitect-rule`            |

The trailer on f6e4789 reads "no consumer-visible path changes" although that commit
touches `composer.json`. The CHANGELOG entry in 38961c0 records the change, and the
`changelog` lane passes over the range. The text is inexact, and it cannot be amended
here (R-GIT-COMMIT-AMEND).

### QA run (targeted only)

- PHPUnit `--filter` over every touched and neighbouring test passes, including the
  Large arkitect tests.
- `-t stan -p` is clean on each touched path, including `bin/arkitect-rule`.
- `-t arch`, `-t cl`, `-t ml` and `-t docsProse` exit 0.
- Rector and the fixer were run over the touched paths, and their changes are
  committed.
- The full pipeline was not run: that is the coordinator's job.

### A hooks-daemon defect observed

`tdd_enforcement` (R-TDD-TEST-FIRST) blocked creating
`src/Pipeline/Lane/PhpArkitect/ArkitectEnvironment.php` although
`tests/Small/Pipeline/Lane/PhpArkitect/ArkitectEnvironmentTest.php` existed in the
worktree. The searched locations show that it resolves `tests/Small/...` against the
main checkout (`/workspace/tests/...`) and only the generic layouts against the
worktree. The sources were written under `untracked/scratch/` and moved into place, and
every test existed and was committed red first. This is worth filing upstream with the
daemon (installed version 3.63.0).

## Task 3.2 — `bash bin/qa` / `sh bin/qa` command-shape rule (not shippable here)

### What was checked

- **Daemon handlers, installed 3.63.0 and upstream v3.67.0**
  (`src/claude_code_hooks_daemon/handlers/pre_tool_use/`). None of them takes arbitrary
  command patterns with a custom correction.

  - `subagent_full_qa_blocker` takes `full_qa_patterns`, but its whole meaning is "a
    sub-agent's full QA run". A second declaration is not possible (one key per
    handler), and its scope must stay sub-agents-only or it denies the coordinator's
    own run. Repurposing it would be faking the rule.
  - `sensitive_content` `public_patterns` judges Write/Edit content, git metadata,
    commits and `gh` bodies, not arbitrary Bash commands.
  - `pipe_blocker`, `npm_command`, `sudo_pip` and the others are fixed-purpose.

- **What php-qa-ci already ships to the daemon.**

  - Config enforcement for four built-in handlers (`scripts/lib/deploy-daemon-config.inc.bash`).
  - The `subagent_full_qa_blocker` block (`src/HooksDaemon/`).
  - The lint-override advisory.

  It ships **no daemon project handlers**. Its classic `php-qa-ci__*.py` hooks are
  removed whenever the daemon is present.

- **The daemon's project-handler mechanism** (`.claude/project-handlers/<event>/*.py`,
  auto-discovered) could carry a php-qa-ci-owned handler today. It was not built, for
  three reasons:

  1. It opens a new deploy channel: php-qa-ci-written Python in a consumer-owned
     directory, with lifecycle, version and collision questions.
  2. The logic would be Python that this package's PHPUnit and PHPStan cannot see,
     against the "logic in a tested language under `src/`" ruling.
  3. The plan already records the Owner's direction that this is "deliberately not a
     php-qa-ci change".

  If the Owner prefers a stopgap, this is the route, and it is a decision for the
  Owner, not for a sub-agent.

- **The consumer `CLAUDE.md` block** could carry one line ("`bin/qa` is PHP; never
  prefix it with `bash`/`sh`"). That is guidance, not a Detector, and it adds to every
  consumer's always-loaded context, which this plan's own goals argue against. It was
  not added; the Owner may choose it as a stopgap.

### What must be filed upstream, and why

The fix belongs in the daemon. It reviews every Bash command before it runs, it already
has the shell segmentation (`utils/shell_segmentation.py`) and evasion helpers
(`utils/command_evasion.py`) that a command-shape rule needs, and only it can stop the
turn the error costs. The rule is better general than php-qa-ci-specific, because the
same error happens with `bash bin/console`, `sh vendor/bin/phpunit` and `bash script.py`.

Proposed issue for `Edmonds-Commerce-Limited/claude-code-hooks-daemon`. No existing
issue matches: searches for "shebang", "interpreter" and "php-qa-ci" found nothing
relevant.

> **Title**: Block a shell interpreter run on a script whose shebang names another
> interpreter (`bash bin/qa` where `bin/qa` is `#!/usr/bin/env php`)
>
> **Problem.** Agents prefix executable scripts with `bash`/`sh` out of habit. When the
> script is not a shell script, as with a PHP entry point such as php-qa-ci's
> `vendor/bin/qa` or Symfony's `bin/console`, the shell parses `<?php` and fails with a
> bare syntax error. The agent loses a turn diagnosing it, and does so on every
> occurrence. Nothing in the target project's documentation reaches the agent at the
> moment it makes the error.
>
> **Proposed rule** (PreToolUse, Bash, gating):
>
> - Match a command segment, after the existing segmentation and env-prefix stripping,
>   whose head is `bash`, `sh`, `dash` or `zsh`, optionally path-qualified, with no
>   `-c`, followed by one script operand that resolves to a readable file under the
>   project.
> - Read the operand's first line. If it is a shebang naming a non-shell interpreter
>   (`php`, `python*`, `node`, `ruby`, `perl`, directly or through `env`), deny.
> - The one-line correction is the same command without the prefix: `bin/qa -t stan`
>   for `bash bin/qa -t stan`, or `php bin/qa ...` when the file is not executable.
> - Allow a shell or absent shebang, a missing or unreadable file (the unseen policy
>   applies), and `bash -c '...'`, which the existing unwrapping already handles.
>
> **Why general rather than per-tool.** The shebang already says what the file is, so
> no per-project pattern list is needed and every PHP, Python or Node entry point is
> covered. A project-pattern variant (`bash|sh` + a configured list such as
> `bin/qa`, `vendor/bin/qa`) would also serve php-qa-ci, which would then declare it
> on composer install as it declares `subagent_full_qa_blocker`.
>
> **Tests.** `bash bin/qa`, `sh vendor/bin/qa -t stan`, `env X=1 bash ./bin/qa` and
> `cd x && bash bin/qa` are denied with the correction. `bash scripts/build.bash`
> (bash shebang), `bash -c 'bin/qa'`, `bash missing-file` and `php bin/qa` are allowed.

### Follow-up for php-qa-ci once the daemon ships it

- If the rule is the general shebang one, php-qa-ci needs nothing; it could add the
  handler to the `REQUIRED_HANDLERS` enforcement in
  `scripts/lib/deploy-daemon-config.inc.bash`, gated on the daemon version.
- If it is the configurable variant, php-qa-ci declares its entry points in the
  consumer's daemon config through the `FullQaBlockerConfigurator` pattern: a
  comment-preserving edit, `config-validate`, a version gate, and a marker for
  ownership.
- Task 3.2 is then closed with that commit, and the plan can complete.
