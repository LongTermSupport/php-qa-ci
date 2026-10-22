# Working on php-qa-ci from a consuming project's `vendor/` (dogfooding)

php-qa-ci is frequently installed **from source** into a consuming project, so
`vendor/lts/php-qa-ci/` is a real git checkout with its own `.git`. When a harness change is
needed while working inside the consumer, work on it in place; no separate clone is required.

- **Edit and commit in the vendored checkout.** Change the include, config or test under
  `vendor/lts/php-qa-ci/` and `git commit` there. Pushing follows the consuming project's policy
  for `lts/*` (typically: commit locally, a human pushes and releases).

- **Dogfood against real consumer code.** The vendored copy is the live copy the consumer's
  `vendor/bin/qa` runs from, so the consumer's next QA run exercises the change with no
  reinstall.

- **Also pass php-qa-ci's own battery before any push.** php-qa-ci ships its own
  `composer.json`, `composer.lock` and `qaConfig/`, so install its own dev environment and run
  its own QA against its own `src/` and `tests/`:

  ```bash
  cd vendor/lts/php-qa-ci
  composer install
  ```

  Then run the battery. Which command, and when, is defined once in
  [prepush-verification.md](prepush-verification.md); follow it there rather than inventing a
  variant. In particular the working default is writable, so the fixers fix.

A consumer's `bin/qa` validates the consumer's code; running it inside `vendor/lts/php-qa-ci`
validates php-qa-ci itself (its `Large` include-level tests, PHPStan, Rector and PHP CS Fixer).
The nested `vendor/lts/php-qa-ci/vendor/` from `composer install` is the package's own dev
environment, isolated from the consumer's tree.

Because php-qa-ci's own source can sit under a path containing `vendor/`, ownership is never
decided by a `vendor/` substring: `phpqaci.unanchoredVendorSubstringCheck` enforces deciding it
against the project root.

From a vendored checkout, php-qa-ci's root `CLAUDE.md` and the nested routing files under
`src/Pipeline/`, `src/PHPStan/` and `scripts/` load when a file beside them is read. Its
`.claude/rules/` path rules may not: treat them as accelerators, and use the root navigation
map.
