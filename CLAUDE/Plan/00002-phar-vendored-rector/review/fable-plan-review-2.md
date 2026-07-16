# Fable Plan Review — 00002 (iteration 2)

> Reviewer: Fable-model sub-agent (Plan/architect persona), 2026-07-16.
> Saved verbatim by the coordinator. Verdict: **APPROVE-WITH-CHANGES** (3 small
> must-fixes remain). Coordinator independently verified the three new claims
> against code before folding into PLAN.md iteration 3:
> - `PhiveUpdatePlugin` does nothing but rector isolation (grep: only
>   `ensureIsolatedTools`→rector; docblock line 72 "no phive needed") → delete entirely.
> - `PhpStanGuardPlugin.php:20,61` carry stale isolated-sub-project references.
> - stub files under `stubs-rector/` are `PHP_VERSION_ID`/`class_exists`-gated
>   (moot on PHP 8.4 with PHPUnit autoloaded) → golden-master claim corrected.

**Verdict: APPROVE-WITH-CHANGES.** The revision is a genuine rework, not lip service: five of six must-fixes are properly resolved, with named tasks, correct line references, and the phive.xml contradiction fully designed out. But the revision introduces or leaves three real problems. First, T3.3 is internally contradictory — "remove the Rector logic entirely" from `PhiveUpdatePlugin` leaves a plugin with literally no remaining purpose (its entire body is the Rector branch), yet the task also mandates "residual path handling silently skips", and it never mentions the `composer.json:101` `extra.class` registration; an implementer cannot tell whether to delete the plugin or ship a hollow shell to every consumer. Second, T3.2 wires `build-rector-phar.bash` into `update` mode, but `post-update-cmd` fires on **every** `composer update` in this repo — on a machine without Box (or offline), that build fails and breaks routine maintainer updates; the script's existing phive-missing grace path (`tool-install.bash:63-77`) has no analogue for the rebuild. Third — verified against the shipped stubs — the plan's claim that the golden-master over php-qa-ci's own src/tests "catches the silent stubs-rector skip" is **false**: all three `Internal/` stubs are `PHP_VERSION_ID < 80100/80000`-gated no-ops on PHP 8.4, and the lone PHPUnit stub is `class_exists`-gated and always masked because `--autoload-file` loads php-qa-ci's vendor, which requires `phpunit/phpunit`. The chosen corpus structurally cannot produce the diff the gate claims to catch. All three are cheap to fix; nothing requires restructuring the plan.

## Must-fix audit

| # | Iteration-1 must-fix | Status | Justification |
|---|---|---|---|
| 1 | Name `PhiveUpdatePlugin` + its test; correct problem statement; silent-skip missing dir | **RESOLVED** (with a new issue, see (b)) | Problem §"TWO writers" now names the plugin as primary cause with correct lines; T3.3/T3.4 are named tasks. The plugin-fate ambiguity T3.3 creates is tracked as new issue (b). |
| 2 | phive.xml contradiction: keep rector out, verify separately, exclude from `rm`, update via build script | **RESOLVED** | "Biggest risks" states the deletion hazard; Key finding 4 and T3.2 implement the required design; T5.2 defers the phive.xml entry to a sister repo with a real source. |
| 3 | Pin the build input; don't delete the lock without a successor | **RESOLVED** | T2.1 relocates the manifest to `build/rector-phar/` as build-input-only and preserves the prepush rationale; T4.3 updates that doc. |
| 4 | Widen the spike | **PARTIALLY RESOLVED** | T1.3 adopts every mechanic, but the golden-master's stated purpose ("catches the silent stubs-rector skip") is false for the chosen corpus (New issue (c)), and iteration-1's "assess whether --autoload-file loading real PHPUnit makes the stubs moot; document either way" was dropped. |
| 5 | Named task for `update-deps.yml` | **RESOLVED** | T4.2 cites both line ranges and the replacement. |
| 6 | Decide php8.3 branch strategy + PHP floor | **RESOLVED** | TX.1 sequences the decision before Phase 4 and links it to the manifest floor + Box check-requirements. |

## New issues

**(a) Dist shipping of `build/rector-phar/` — neutral; no action strictly required.** No `.gitattributes`/`export-ignore`, no `archive.exclude` in `composer.json`, so every tracked file ships in the consumer dist zip. The relocated manifest is weight-neutral and precedent-covered (`tools/rector/composer.{json,lock}` ship today). Two minor follow-ons the plan omits: (1) `.gitignore` needs a `/build/rector-phar/vendor/` entry — T2.2 says "git-ignored build vendor" but no task adds the line while T4.1 removes the old one; (2) `rector.phar` (~25-30 MB) joins every consumer dist zip forever — add dist-zip growth to the T5.1 tripwire criteria. Nice-to-have.

**(b) `PhiveUpdatePlugin` has no remaining purpose — T3.3 is self-contradictory and under-specified. MUST FIX.** The plugin's entire post-lifecycle body is `ensureIsolatedTools()`→Rector; nothing else. "Remove the Rector logic entirely" leaves event subscriptions that do nothing, yet T3.3 also mandates "residual path handling silently skips a missing dir" — there is no residual handling once the logic is gone. Decide: **delete** the class, `tests/Small/ComposerPlugin/PhiveUpdatePluginTest.php`, and the `composer.json:101` registration (clean answer), or justify a shell. If deleting: `PhpStanGuardPlugin.php:61` ("after PhiveUpdatePlugin has installed/updated phars") is a second stale reference (T4.3 lists only the `:20` docblock), and the priority `-10` rationale becomes vestigial (harmless). Note in the plan: the one-time `<error>Directory not found</error>` during the *transitional* consumer update comes from the **old, already-installed** plugin code and cannot be fixed from this repo; verified non-fatal.

**(c) The golden-master corpus cannot trigger the stub-skip failure mode it claims to catch. MUST FIX.** `Internal/*.php` stubs gated on `PHP_VERSION_ID < 80100`/`< 80000` (no-ops on 8.4); `PHPUnit/Framework/TestCase.php` gated on `!class_exists(...)`, always false because `--autoload-file` loads php-qa-ci's vendor which requires `phpunit/phpunit`. So on php-qa-ci's own src/tests the stubs-skipped phar and the current install are identical by construction, and T1.3's "(catches the silent stubs-rector skip)" is false confidence. The failure mode is essentially **moot in this usage**; the only residual exposure is a consumer whose `testsDir` gets the rector-phpunit pass without PHPUnit in its autoload chain. Fix: keep the golden-master (it still catches general drift — set imports from `phar://`, config resolution, worker spawn), correct the parenthetical, record the stubs-moot analysis in the spike results, optionally add a no-PHPUnit-fixture case.

**(d) `tool-install.bash` update-mode rebuild has no Box-missing grace path. MUST FIX.** `post-update-cmd` runs `tool-install.bash update` on every `composer update` (`composer.json:116-118`), including incidental triggers. T3.2's "call build-rector-phar.bash" means: no Box (or offline) → build fails → `set -e` kills the script → `composer update` fails. Mirror the phive-missing branch (63-77): skip gracefully when `rector.phar` exists and Box is absent, and (given reproducible builds) rebuild only when `build/rector-phar/composer.lock` changed or an explicit flag is passed. Minor nit: T3.2's "(it is not in phive.xml)" is not what protects it from the `rm` — the loop globs `"$VENDOR_PHAR_DIR"/*.phar` regardless (line 105); the exclude instruction is correct, the rationale imprecise.

## Residual gaps

- **CI gate that the phar actually runs: adequate, but state it.** `qa.yml`'s matrix includes `rector`; once T3.1 repoints `rectorBin`, every PR run exercises the committed phar end-to-end in read-only mode (including exit-2). Verification never says this committed CI gate exists; add one line.
- **Mid-transition prepush battery: safe as sequenced.** Wiring (Phase 3) precedes deletion (Phase 4); the phar is committed before `tools/rector/` is removed, so `bin/qa -t rector` never loses its binary. Keep the phase boundary as separate commits.
- **Stale research doc.** `research-phive-distribution.md:62-69` still recommends adding a `rector` entry to `phive.xml` — the exact premise iteration 1 debunked and the plan now forbids. Only the box research file was corrected. Add a superseded-by-review note.
- **update-deps.yml detection under reproducible builds**: an unchanged lock → byte-identical phar, so T4.2's detection should key off `build/rector-phar/composer.lock` diff, not the phar binary.

## Verdict

**APPROVE-WITH-CHANGES.** The iteration-1 blockers are substantively fixed (5 resolved, 1 partial); the architecture — spike-gated, pin-preserving, phive.xml-free, delete-last — is sound and verified. Three remaining must-fixes, all small-scope plan edits, no restructuring:

- [ ] **T3.3: decide the plugin's fate** — delete `PhiveUpdatePlugin` + test + `composer.json:101` registration + fix `PhpStanGuardPlugin.php:61` (recommended), or justify a no-op shell; drop the contradictory "residual path handling" clause. Note the transition error is old installed code, non-fatal.
- [ ] **T3.2: add a Box-missing/no-op grace path** to the update-mode rebuild, mirroring the phive-missing skip.
- [ ] **T1.3: correct the golden-master claim** — the src/tests corpus cannot surface the stub skip; record the stubs-moot analysis, optionally add a no-PHPUnit-fixture case.
