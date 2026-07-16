# Fable Plan Review — 00002 (iteration 3)

> Reviewer: Fable-model sub-agent (Plan/architect persona), 2026-07-16.
> Saved verbatim by the coordinator. Verdict: **APPROVE — zero must-fixes remain.**
> Convergence reached. The one cosmetic nit (Goal + Phase-3 heading "rework" →
> "delete") was applied by the coordinator after this review.

All checks complete. Every claim I needed to verify holds against the code: `composer.json:101` registers the plugin, `PhpStanGuardPlugin.php:20-21` and `:61` carry the stale references exactly as T3.3 says, `tool-install.bash:63-77` is the phive-missing grace path T3.2 mirrors, the `rm` glob is at line 105, the test file exists, and the research doc now carries the SUPERSEDED note at lines 62-68.

## Verdict summary

**APPROVE.** Zero must-fix items remain. The plan has converged: spike-gated, pin-preserving, phive.xml-free, delete-last, with the plugin deletion, rebuild grace path, and spike-scope claims all now accurate against the code. The plan is ready to implement.

## Must-fix audit

1. **T3.3 plugin fate** — **RESOLVED.** T3.3 now opens "DELETE `src/ComposerPlugin/PhiveUpdatePlugin.php` entirely", enumerates the class, `PhiveUpdatePluginTest.php`, the `composer.json:101` registration, and both `PhpStanGuardPlugin.php` fixes (`:61` reference and `:20` docblock); the contradictory "residual path handling" clause is gone, and the transitional `<error>Directory not found</error>` note (old installed code, non-fatal, release-note sentence) is present.
2. **T3.2 Box-missing grace path** — **RESOLVED.** T3.2 adds "a Box-missing / no-op grace path mirroring the phive-missing skip (63-77)": skip when `rector.phar` exists and Box is absent, rebuild only on `build/rector-phar/composer.lock` change or explicit flag, with the `post-update-cmd` rationale (`composer.json:116-118`) cited; the imprecise "not in phive.xml protects it from `rm`" rationale was also corrected to an explicit skip of the line-105 glob.
3. **T1.3 golden-master claim** — **RESOLVED.** T1.3 carries an explicit "CORRECTION (review-2 §c)" stating the src/tests corpus cannot surface the stub skip (with the `PHP_VERSION_ID`/`class_exists` gating reasons), records the stubs-moot analysis into `spike-results-1.md` (recovering the dropped iteration-1 item), and adds the no-PHPUnit rector-phpunit fixture case; the golden-master's remaining purpose is correctly restated as catching general phar drift.

## New contradictions?

None that block. One cosmetic wording residue (now fixed by the coordinator): the Goal paragraph and the Phase 3 heading still said *rework* while T3.3 says *DELETE* — leftover iteration-2 prose, not a competing instruction, since T3.3 is the only task-level directive and no other task treats the plugin as surviving. The T3.2 grace-path wording is internally coherent (exists+no-Box → skip; missing+no-Box inherits the hard-fail of the mirrored phive branch, which is the right behaviour). The verification section, T4.2, and T5.1 all align; the research doc's SUPERSEDED banner correctly quarantines the stale phive.xml recommendation.

## Verdict

**APPROVE.** Zero must-fix items remain. The plan has converged and is ready to implement.
