# withIgnoredPaths() reaching the scanning lanes: subagent report

Plan 00013, Task 3.3 (D7). Worktree branch off `bugfix/known-defects-sweep`. Not pushed or
merged.

## Commits

| Commit    | What                                                                                  |
| --------- | ------------------------------------------------------------------------------------- |
| `292d3c0` | `red:` the detector, plus the defect-record entries for the known gaps                |
| `361a07c` | `style:` fixer and Rector drift already on the branch, committed apart (not this fix) |
| `7218f7f` | `fix:` the six lanes, with unit tests, docs and the CHANGELOG entry                   |
| `f23e27d` | `chore:` this repository's own repeated `tests/assets` exclusions dropped             |

## Class and search (method 3.1)

- **Class**: a lane that scans the checked paths drops the project's `withIgnoredPaths()`.
- **Hazard**: content a project declared not to be scanned, such as fixtures with
  deliberate violations, is reported, so the project has to repeat each path in every
  tool's own config or live with false findings.
- **Technique 1**: a text search for `pathsToIgnore` over `src/`. It found five consumers:
  Rector, PHP Lint, OPcache, ShellCheck and analysedPaths.
- **Technique 2**: reading every lane that walks `pathsToCheck`, `srcDir`, `testsDir` or
  a Composer autoload root. Beyond the five lanes the brief named, it found
  **psr4Validate**, which walks the autoload roots. This repository repeated `#tests/assets#`
  in its psr4 ignore list for exactly this reason. It also found **PHPArkitect** and
  **Infection**.
- **Behaviour pinned**: each lane's own test file, and the detector probes behaviour (see
  below).

## The detector

`tests/Small/Pipeline/Lane/IgnoredPathsReachEveryScanningLaneTest.php` puts every lane in
`ShippedTools::all()`, the platform lanes included, into one of three groups, each lane with
a reason:

- **SCANS**: the lane must honour the setting.
- **DOES_NOT_SCAN**: the reason is given.
- **KNOWN_GAPS**: each gap must have a `deferred` entry in `qaConfig/defect-record.neon`
  whose `found` names the lane's source file.

An unclassified lane fails the test. Each scanning lane is then probed in one of three ways:

- **reaches**: the ignored path appears in the argv, the environment, or a file the lane
  wrote under `var/qa/`.
- **filters**: a file planted under the ignored path never reaches the tool.
- **skips**: a violation planted there does not fail an in-process lane.

Every probe also runs without the setting and must get the opposite result, so a probe that
cannot fail is reported as vacuous. Self-tests show each probe firing on a lane that drops the
setting.

The detector is a test, as the brief asked. The method prefers a Detector that reads code (3.2).
This class is about runtime wiring between a config value and a process, so no PHPStan rule
could express it.

**Red run at 292d3c0**: phpstan, deadCode, phpCsFixer, phpStrictTypes, phpcpd and psr4Validate
failed. rector, phpLint and opcache passed.

## How each lane changed

| Lane           | How the ignored paths reach the tool                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| phpstan        | An `excludePaths.analyse` block in the wrapper neon, built by `ExcludePathsNeon`. A `-p` run wholly inside an ignored path is **skipped**. Without the skip, PHPStan stops at "No files found to analyse", exits 1, and the lane fails red.                                                                                                                                                                                                                                    |
| deadCode       | The same block in `dead-code.neon`. A member reached only from ignored code counts as unused, exactly as it already does for any `excludePaths` entry in a project's own neon. This is documented.                                                                                                                                                                                                                                                                             |
| phpCsFixer     | A generated `var/qa/phpCsFixer/php_cs.php` (`IgnoredPathsConfig`) requires the resolved config inside a closure, wraps its finder in an `IteratorAggregate` that drops real paths under an ignored path, and is passed as `--config`. This is needed because `--path-mode=intersection` only narrows the finder (`ConfigurationResolver::resolveFinder()` in the shipped phar). It works with any copied `php_cs.php` or finder. It is written only when something is ignored. |
| phpStrictTypes | Skips files under an ignored path. A fixture is neither reported nor rewritten.                                                                                                                                                                                                                                                                                                                                                                                                |
| phpcpd         | One `--exclude` per path. phpcpd matches by substring (`FileFinder::isExcluded`), so a directory carries a trailing slash.                                                                                                                                                                                                                                                                                                                                                     |
| psr4Validate   | One more ignore regex per path, anchored to its real path, which is what `Psr4Validator` matches against.                                                                                                                                                                                                                                                                                                                                                                      |

`IgnoredPaths` (`src/Pipeline/Config/`) resolves the entries once and compares whole path
segments, so `tests/assets` never swallows `tests/assetsExtra`.

## The PHPStan excludePaths decision

- **`analyse`, not `analyseAndScan`.** Analysed code may reference a class declared under
  an ignored path, for example a test that loads a fixture class. That class must stay
  discoverable. `analyseAndScan` would turn each such reference into `class.notFound` in
  code that is analysed.
- **Optional `(?)`.** In phpstan 2.2.16, `ValidateExcludePathsExtension` refuses a required
  entry that matches nothing, and this repository's own stale `src/PHPUnit/TestDox` ignore
  would have crashed PHPStan.
- **Absolute paths.** A relative entry resolves against the wrapper's directory under
  `var/qa/`.
- **Merging with a project's own list.** `NeonAdapter` normalises a plain-list
  `excludePaths` into `{analyseAndScan, analyse}` for each file before the files merge, so
  the block combines with a project's own list.
- **Effect on this repository.** Removing `./../tests/assets/*` from `qaConfig/phpstan.neon`
  moves `tests/assets` from analyseAndScan to analyse. `stan -p tests` and `stan -p src`
  show no new finding from that.

## Verified against the real tools on this repository

- **PHP CS Fixer.** `list-files` lists 137 `tests/assets` files through the plain config
  and 0 through the wrapper. A plain `fix --dry-run` over `tests` lists 46 `tests/assets`
  lines, and the lane lists none.
- **PHPStan.** The wrapper neon is accepted, and nothing under `tests/assets` is reported.
- **PHPCPD.** The run gets `--exclude <root>/tests/assets/`, and the JSON report has no
  `tests/assets` entry.
- **Dead code.** The `dcd` lane runs with the new block.
- **psr4 and strict types.** `psr4` and `st` are green with the psr4 ignore-list entry
  removed. `composer install` itself warns about the `tests/assets/psr4` fixtures, which
  shows those fixtures would fail.

## Not fixed, and why

These are deferred in `qaConfig/defect-record.neon`, and the detector holds the first two.

- **PHPArkitect.** `ClassSet::excludePath()` goes through `Arkitect\Glob::toRegex`, an
  unanchored regex. `src/Legacy` would therefore also drop every directory named `Legacy`
  deeper in `src/`. That is a silent narrowing I may not choose. `withArkitectExcludedPaths()`
  stays the knob for now.
- **Infection.** Infection takes no exclusion on the command line, only `--filter`. A
  rewritten `infection.json` would have to re-anchor every relative path in it.
  `source.excludes` stays the knob.
- **Stale ignore.** `src/PHPUnit/TestDox` is in `qaConfig/qa.php`, and `/TestDox/` is in
  `qaConfig/infection.json`, but the directory does not exist. The class is "a declared
  exclusion that matches nothing". The analysedPaths lane catches that class for
  `withUnanalysedPath()` but not for `withIgnoredPaths()`. Removing the entry is trivial;
  the detector for the class is the deferred part.

## Owner question: the next wider rule, not built

The wider rule would require every lane that walks project files to honour
`withIgnoredPaths()`, including those whose scope is not the checked paths: twigCsFixer,
twigLint, yamlLint, markdownLinks and docsProse. ShellCheck already does.

I did not build it. The documented contract (`docs/defence-before-fix-defaults.md`, "Sweep
scope") ties the setting to the checked paths, and those lanes have their own scope settings.
Whether the contract should widen is the Owner's call. Under method section 4, leaving the
wider rule unbuilt for a reason other than an absent hazard is also the Owner's decision.

## Found on the way and not touched

All of these were on the base branch already.

- `bin/qa -t stan -p src`: `Psr4Validator.php:178` `missingType.generics`. It is reported
  with the old `phpstan.neon` too.
- `bin/qa -t stan -p tests`: `tests/Small/AgentContext/ActiveDefencesSummaryTest.php:56`
  `phpqaci.repeatedStringLiteral`. It came in with the Plan 00016 merge.
- `QA_READONLY=1 bin/qa -t fixer -p tests`: `tests/Small/WorkflowActionRuntimeTest.php`
  has a pending `binary_operator_spaces` fix. It came in with ced13f0.
- `bin/qa -t dcd`: `ArkitectRuleProbe::main` is reported unused, because `bin/arkitect-rule`
  is not in `withDeadCodeEntryPoints()`.
- The fixer and Rector changed `DefectRecordReader` imports and the argument order in
  `QaConfigBuilder::with()`. Those changes are committed apart in 361a07c.
- The worktree's hooks-daemon `tdd_enforcement` resolved tests against the main checkout,
  which is the defect already deferred in the record. The three new source files were
  therefore placed by `cp` after their tests existed and were red.

## Checks run

| Check                                                                 | Result                                                                                                       |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `XDEBUG_MODE=off php bin/phpunit --no-coverage --exclude-group large` | OK, 1615 tests                                                                                               |
| `bin/qa -t stan -p src/Pipeline`, `-p tests/Small/Pipeline`           | 0 errors                                                                                                     |
| `bin/qa -t stan -p src`, `-p tests`                                   | 1 error each, both pre-existing (above)                                                                      |
| `bin/qa -t fixer -p src/Pipeline`, `-p tests/Small/Pipeline`          | clean                                                                                                        |
| `bin/qa -t rector -p src/Pipeline`, `-p tests/Small/Pipeline`         | clean                                                                                                        |
| `bin/qa -t cl`                                                        | pass                                                                                                         |
| `bin/qa -t ml`                                                        | pass                                                                                                         |
| `bin/qa -t dp`                                                        | pass                                                                                                         |
| `bin/qa -t psr4`, `-t st`, `-t pij`                                   | pass                                                                                                         |
| `bin/qa -t cpd`                                                       | pass (informational)                                                                                         |
| `bin/qa -t dcd`                                                       | 4 findings: 1 mine, fixed afterwards (a repeated literal in `PhpstanToolTest`); the other 3 are listed above |
