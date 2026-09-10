# Post-Fix Re-Audit #2 — Bash + Docs (V5 verification loop)

**Auditor:** post-fix-reaudit agent (fresh eyes) · **Date:** 2026-07-15
**Repo:** /workspace · **Branch:** php8.4 · **HEAD:** 7ddbc51
**Scope:** (A) regressions / new defects introduced by waves W1–W5, focusing on
cross-wave seams; (B) residual rot — anything claimed-fixed still broken, or any
doc claim now false against HEAD.
**Method:** every claim verified against HEAD code, never against audit text.
Reproductions built where feasible (mock composer proxy, registry diff vs
pre-refactor, live `bin/qa -t` smoke).

---

## VERDICT: SHIP-WITH-NITS

The five remediation waves are **faithful and correct**. I found **no regression
introduced by the waves** — the W5 registry/driver refactor preserves the
pre-refactor contract byte-for-byte, the migrated fragments match their historic
loops, and the W1/W2 correctness + read-only fixes are all present and working on
HEAD. Baseline gates are green.

The one item that keeps this from a clean SHIP is a **claimed-fixed finding that
is not actually fixed** (M-053, MAJOR): the reworked composer-proxy self-parse
still fails against the real composer bin-proxy format this very repo emits. It is
a *pre-existing* latent defect (the old parse failed too), so it is not a wave
regression — but it was marked resolved in W5 and its own docstring makes a false
claim, so it belongs in the register as reopened, not closed. It does not block
the wave work; it needs a follow-up commit.

**Finding counts:** CRITICAL 0 · MAJOR 1 · MINOR 1 · INFO 2

---

## Findings

### MAJOR

#### R-01 — M-053 "fix" still fails on the real (split) composer bin-proxy format; docstring claim is false
**Files:** `bin/qa:29-63` (the `COMPOSER_RUNTIME_BIN_DIR` branch, M-053 rework in 9836f21)

The new parse resolves `qaDir` by scanning the proxy's single-quoted path
fragments and testing, for each fragment `F`, whether `$binDir/F` is (or contains)
the `qa` script:

```bash
qaProxyCandidate="$binDir/$qaProxyFragment"
if [[ -f "$qaProxyCandidate/qa" ]]; then ...
elif [[ -f "$qaProxyCandidate" && "$(basename "$qaProxyCandidate")" == "qa" ]]; then ...
done < <(grep -Po "(?<=')[^']+(?=')" "$binDir/qa")
```

This only ever joins `$binDir` to **one** fragment. But modern Composer 2.x emits
the referenced bin path as **two concatenated single-quoted literals** —
`__DIR__ . '/..'.'/lts/php-qa-ci/bin/qa'`. `grep -Po "(?<=')[^']+(?=')"` extracts
`/..` and `/lts/php-qa-ci/bin/qa` as **separate** fragments, so the `..` (needed to
climb out of the bin dir) is never joined to the package-relative fragment.
Neither `$binDir/..` nor `$binDir//lts/php-qa-ci/bin/qa` resolves to the qa
script → `qaDir` stays empty → **bin/qa prints "could not resolve … library
directory" and exits 1.**

This split format is not hypothetical — it is exactly what this repo's own
Composer-generated proxies use. `bin/phpunit:122`:
```
return include __DIR__ . '/..'.'/vendor/phpunit/phpunit/phpunit';
```

**Reproduction** (faithful mock built to match the real bin/phpunit proxy format):
- Split proxy (`'/..'.'/lts/php-qa-ci/bin/qa'`): new parse → `qaDir=''` → **exit 1**.
- Non-split proxy (`'/../lts/php-qa-ci/bin/qa'`, single fragment): new parse →
  resolves correctly. So the fix only handles the *non-split* form.
- The **old** parse (`cd $binDir/$(grep -Po "[^']+php-qa-ci[^']+" …)`) *also*
  fails on the split form → **this is not a wave-introduced regression**, but a
  pre-existing latent bug the M-053 rework did not resolve.

**False claim:** the M-053 docstring states *"Independent of the install-dir name;
the php-qa-ci layout resolves unchanged."* — this is FALSE for the split format,
which is the format real Composer produces.

**Blast radius (why MAJOR, not CRITICAL):** the `COMPOSER_RUNTIME_BIN_DIR` branch
only executes when php-qa-ci is invoked through a Composer-generated **PHP proxy**
rather than a symlink — i.e. Windows, or `bin-compat: full`/proxy installs, not
the common Linux-symlink case (which takes the `else`/self-test branch). Real but
bounded, and it hard-fails loudly rather than silently mis-running.

**Also (INFO, folded here):** the branch is **untested** — no test references
`COMPOSER_RUNTIME_BIN_DIR` or the proxy parse, so CI cannot catch this. A
characterisation test that runs the parse block against both proxy shapes (like
the mock I built) would pin it.

**Recommendation:** reopen M-053. Reconstruct the full relative path by
concatenating consecutive quoted fragments on the `include`/`require` line before
resolving (or, more simply, take the last quoted fragment ending in `/qa` and
prepend every quoted fragment on that same line). Fix the docstring. Add the
two-shape test.

---

### MINOR

#### R-02 — `install_signed` ownership detection is content-wide; `-f` vs `-e` asymmetry with `install_seed_once`
**File:** `scripts/lib/consumer-write.inc.bash:71-87` (and `:94-104`)

`install_signed` decides "this hook is ours, overwrite it" with:
```bash
if [[ ! -f "$dst" ]] || grep -q "$marker" "$dst"; then install_owned_file ...
```
`grep -q` matches the marker **anywhere** in the file, including comments. A
foreign `.git/hooks/pre-commit` that merely *mentions* the string
`PHP-QA-CI-HOOK-SIGNATURE` (a comment, a copied snippet) is mis-classified as
php-qa-ci-owned and silently overwritten. The marker is distinctive so real-world
collision is unlikely, but the check should anchor the marker to the line/region
it actually writes, not scan the whole file.

Secondary edge: `install_signed` gates on `-f` (regular file) while the sibling
`install_seed_once` correctly gates on `-e` (any existing entry). At a **dangling
symlink** `.git/hooks/pre-commit`, `-f` is false → `install_signed` treats it as
absent and `cp`s **through** the symlink, writing to the symlink's (possibly
out-of-tree) target. Very narrow, but the two helpers should use the same
existence test for the same class of singleton path.

Neither is a wave regression — both are properties of the new shared helper as
written. Non-blocking.

---

### INFO

#### R-03 — duplicate "Running Single Tool" banner
`includes/options.inc.bash:142` and `bin/qa:260` both echo `Running Single Tool:
$singleToolToRun`. Purely cosmetic — verified the tool executes once (single PASS
line in the live smoke). Drop one.

#### R-04 — `src/` PHPStan rules & Composer plugins still unit-untested (M-077 unchanged)
Out of my bash/docs remit but noted for completeness: the 4 Composer plugins and
the rule classes under `src/PHPStan/Rules/` remain without dedicated unit tests.
M-077 was INFO on the master register and was not slated for a wave; no change.

---

## Residual-rot sweep (mission B) — CLEAN

Every previously-flagged claim I re-checked against HEAD is now correct. Notable
verifications (each confirmed against code, not audit text):

| ID | Claim re-verified | HEAD status |
|---|---|---|
| M-001 | PSR-4 fragment restored, guarded | `psr4Validate.inc.bash` uses `qaSimpleTool`; `ToolFragmentLivenessTest` asserts every fragment has executable code — **fixed + guarded** |
| M-002 | PHPUnit-annotations gate retired | fragment + `bin/phpunit-check-annotation` + `src/CheckAnnotations.php` all **deleted**, no orphans, not in registry |
| M-003 | strict-types `find` precedence | `find … -type f \( -name '*.php' -o -name '*.phtml' \)` — **both scanned** |
| M-004 | strict-types CI/read-only guards | `qaReadOnly` branch present — **fixed** |
| M-009 | derive-before-override | `bin/qa`: `setConfig`(191) → source override(211) → `deriveDependentConfig`(221) — **fixed** |
| M-012 | single-tool exit capture | `if runTool …; then exitCode=0; else exitCode=$?` — **fixed** |
| M-013 | vendor hook `IFS='\|\|\|'` | now `IFS='\|'` for the tab-split + `${rest%%\|\|\|*}` for the triple-pipe, with a warning comment — **fixed** |
| M-024 | packageType bypasses guard | runs via `runToolGuarded` in `qaRunPhase` — **fixed** |
| M-025/M-026 | array quoting / `eval` | `phpLint.inc.bash` quoted arrays via `qaSimpleTool`, no `eval` — **fixed** |
| M-027/M-048 | composerChecks read-only + quoting | `normalize --dry-run` gated on `qaReadOnly`; `"$(which composer)"` quoted — **fixed** |
| M-005 | Laravel fabrication | CLAUDE.md: "There is no Laravel/`artisan` detection" — **fixed** |
| M-006 | phantom config cascade | CLAUDE.md: "no `configDefaults.inc.bash` … beyond `generic/`" — **fixed** |
| M-029 | PHPStan rule counts | 14 always-on (10 `rules:` + 4 tagged services) and 12 opt-in (8 generic auto-loaded + 4 symfony; the 13th, `ForbidMagicStringAssertionRule`, is a documented non-auto-loaded cherry-pick) — **counts accurate** |
| M-030 | "four Composer plugins" | README says four; `composer.json` registers exactly four — **fixed** |
| M-031/M-040/M-041/M-042 | phase numbering / PHIVE-install / coverage default / PHP version | all corrected in CLAUDE.md — **fixed** |

I specifically tried to break M-029 (my first grep suggested 13 opt-in rules) and
found the docs correct once the experimental cherry-pick rule is excluded — the
docs count only auto-loaded rules, which is the right denominator.

---

## Cross-wave seam verification (mission A) — no regressions

1. **W5 tool registry vs pre-refactor state.** Diffed `toolRegistry.inc.bash`
   against `git show d824341^:includes/options.inc.bash` and the four
   `all*Tools.inc.bash` phase files:
   - Alias map: all 22 `-t` case arms reproduced exactly (incl. `uniterate` →
     `phpunit` + `phpUnitIterativeMode=1` via `ONSELECT`, applied with `printf -v`,
     not `eval`).
   - Path-support: identical partition (7 path-supporting, rest not).
   - Phase order: identical sequences for all four phases.
   - The `notQuick`/`infection` gates faithfully encode the pre-refactor
     `if phpqaQuickTests==1 … else` / `if useInfection==1` conditionals.
   The `ToolRegistryCharacterisationTest` is **genuine golden data** (hard-coded
   `GOLDEN_*` constants, independent extraction), not a tautology.

2. **W5 shared driver `qaSimpleTool` vs the five migrated fragments' historic
   loops.** `psr4Validate`, `phpLint`, `markdownLinks`, `packageType`,
   `sensitiveParameterUsage` all delegate to `qaSimpleTool`, whose
   if-condition capture + `tryAgainOrAbort` is behaviourally identical to each
   fragment's old `while ((rc>0))` loop. `phpLint` additionally dropped its old
   `eval` and `set +e/-e` toggling (M-026) and gained correct array quoting
   (M-025).

3. **W2 read-only `composerChecks` on W1 `deriveDependentConfig` ordering.** No
   interaction bug: dump-autoload is deliberately *not* gated (generated artefact);
   normalize/diagnose are gated. Ordering fix independent.

4. **Ownership helpers used by all three deploy scripts.** `deploy-skills.bash`,
   `setup-claude-qa-agent.bash`, `install-github-actions.bash` all `source`
   `scripts/lib/consumer-write.inc.bash` and route every consumer write through
   `install_owned_file`/`install_owned_tree`/`install_signed`/`install_seed_once`.
   The only raw `cat >` (`setup-claude-qa-agent.bash:91`) targets an `mktemp`
   render file, then copies via `install_owned_file` — correct. (Edge cases in
   R-02.)

5. **M-053 proxy parse vs a realistic mock** — see R-01.

---

## Verification commands & results

```
$ git rev-parse HEAD
7ddbc5176be52ed136a73077dfcaa9191a6b6789

$ bash -n <all includes/scripts/git-hooks/ci.bash/bin/qa>
(clean — no syntax errors)

$ shellcheck -S error <same set>
exit 0, 0 findings                         # the CI gate is green

$ ./bin/phpunit --no-coverage tests/Small/
OK — Tests: 209, Assertions: 1991, PHPUnit Notices: 12   # matches expected 209

$ QA_READONLY=1 CI=true ./bin/qa -t bnp
exit 0
  Running Single Tool: branchNamePolicy
  [branchNamePolicy] PASS — branch 'php8.4' is exempt (default/protected)
  Aggregate (read-only) run: every QA tool passed.
  # proves W5 registry resolves + runs a tool end-to-end; read-only+aggregate work

# M-053 reproduction (faithful mock matching bin/phpunit's split proxy format)
split proxy  '/..'.'/lts/php-qa-ci/bin/qa'  -> new parse qaDir=''   -> FAILS (exit 1)
non-split    '/../lts/php-qa-ci/bin/qa'      -> new parse resolves OK
old parse on split proxy                     -> also FAILS (not a wave regression)

# Registry fidelity
git show d824341^:includes/options.inc.bash        -> alias/path arrays match registry
git show d824341^:includes/generic/all*Tools.inc.bash -> phase orders match registry

# Rule counts
rules-default.neon:          10 under rules: + 4 tagged services = 14 always-on
rules-optional.neon:          6 under rules: + 2 tagged services =  8 auto-loaded
rules-optional-symfony.neon:  4 under rules:                     =  4
                                                       opt-in total = 12  (matches docs)
composer.json extra.class:   4 plugins (matches "four")
```

**Note on the 12 PHPUnit notices:** deprecation/notice output from the suite, not
failures; the run is green (exit 0). Not investigated further — out of scope and
non-failing.
