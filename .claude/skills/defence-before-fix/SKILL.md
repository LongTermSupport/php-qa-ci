---
name: defence-before-fix
description: |
  Defence Before Fix (DBF) in a PHP project that uses php-qa-ci: points at the method's one
  authoritative version and lists the php-qa-ci tools an agent uses while following it.

  Use when:
  - A bug, failing check, red QA run or review finding has been found in a PHP project
  - "DBF", "DBF this", "defence before fix", "create a PHPStan rule for this bug"
  - "detect this pattern with static analysis", "ratchet this bug class"
  - A php-qa-ci failure banner printed "Defence Before Fix (DBF)"
allowed-tools: Read, Bash, Task, Skill, WebFetch
---

# Defence Before Fix with php-qa-ci

The method is not described here. It is specified at https://defence-before-fix.github.io/,
and this skill only says where to get it and which php-qa-ci tools serve it.

## Run the method

With the Defence Before Fix plugin for Claude Code, which carries the method's skill:

```
/plugin marketplace add Defence-Before-Fix/claude-plugin
/plugin install defence-before-fix@defence-before-fix
```

then invoke `/dbf`, or say "DBF".

Without the plugin, fetch the agent prompt and follow it:
https://defence-before-fix.github.io/defence-before-fix-project-prompt.md
(offline, a vendored copy is at
`vendor/lts/php-qa-ci/remote-docs/defence-before-fix.github.io/defence-before-fix-project-prompt.md`).

## php-qa-ci mechanics

Paths are for a consuming project; in the php-qa-ci repository itself drop
`vendor/lts/php-qa-ci/`, and the bin directory is `bin/` rather than `vendor/bin/`.

- `vendor/bin/rules [--json]` lists the active defences: PHPStan rules from the resolved
  `phpstan.neon`, the always-on pipeline lanes, and the project's `ignoreErrors` record.
- `vendor/bin/rule-doc <identifier>` resolves an identifier printed in a failure to its
  documentation page; `--list` prints every identifier resolvable in the project. A
  project's own identifiers resolve through the indexes it declares in
  `qaConfig/rule-docs.json`.
- `vendor/bin/phpstan-rule <identifier> <path>` runs PHPStan with the project's own config
  over one path and reports whether that one rule fired there (exit 1, with locations) or
  not (exit 0).
- Bundled rules live in `vendor/lts/php-qa-ci/src/PHPStan/Rules/`, their pages in
  `vendor/lts/php-qa-ci/docs/phpstan-rules/` (the identifier index is that directory's
  `README.md`), and the pipeline lanes' pages in `vendor/lts/php-qa-ci/docs/tools/`.
- A project's own PHPStan rules go in `qaConfig/PHPStan/Rules/`, written to
  `qaConfig/PHPStan/CLAUDE.md`. The `php-qa-ci_phpstan-rule-creator` agent writes them;
  example rules for common patterns are in `examples/` beside this file.
- Structural conventions are PHPArkitect rules in `qaConfig/phparkitect.php`, not PHPStan.
  `vendor/bin/arkitect-rule <because> <path>` runs those rules over one fixture path and
  reports whether the rule with that `because` clause fired there (exit 1) or not (exit 0).
- Which QA command to run, and when, is defined once in
  `vendor/lts/php-qa-ci/CLAUDE/prepush-verification.md`.
- How this toolchain applies the method, and which decisions belong to the Owner, is in
  `vendor/lts/php-qa-ci/CLAUDE/DefenceBeforeFix.md`.
