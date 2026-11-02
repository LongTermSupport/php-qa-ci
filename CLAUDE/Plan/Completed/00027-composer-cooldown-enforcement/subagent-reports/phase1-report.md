# Phase 1 report: composerChecks cooldown assertion

Built inside the existing `composerChecks` lane (identifier `phpqaci.composerChecks`), step 2 after
`composer diagnose`.

- `src/Pipeline/Lane/ComposerChecks/CooldownPolicyChecker.php` and `CooldownReport.php`: the pure
  check over the decoded `composer.json`, the `composer --version` output, today's date and the
  internal vendors.
- `ComposerChecksTool`: probes `composer --version --no-ansi`, prints findings and the override
  listing, fails only when enforcement is on. Clock: `Pipeline\Lock\ClockInterface`.
- Settings: `withComposerCooldownEnforced()` / env `enforceComposerCooldown`,
  `withComposerCooldownInternalVendors()`, `withoutComposerCooldownCheck(reason)` (empty reason
  refused with a LogicException).
- Docs: `docs/tools/composerChecks.md` (section "The cooldown policy"), `docs/configuration.md`
  row, `CHANGELOG.md` Added entry.
- Composer 2.11 `ignore` shapes taken from its bundled JSON schema: list of names, or map of
  pattern to string, null, rule object or list of rule objects.

Decisions: a probe failure or unreadable version is a named finding; an unrecognised shape is a
named finding; a rule object with a constraint or `on-block: false` does not count as exempting an
internal vendor.

QA run: phpunit on `tests/Small/Pipeline` (1945 tests, green, before the final fixer pass), the
four touched test files again afterwards; Rector and PHP CS Fixer through `bin/qa -t rector|fixer -p <path>` writable then `QA_READONLY=1`, all exit 0; `markdownFormat` clean on touched files.
Not run: full pipeline, PHPStan, infection, markdownLinks (not a `-t` token).
