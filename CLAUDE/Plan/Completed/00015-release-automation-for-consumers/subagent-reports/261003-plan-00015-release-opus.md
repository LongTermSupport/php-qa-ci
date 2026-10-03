# Plan 00015 — Phases 1–3 and Task 4.1 (worktree agent report)

Branch `agent-abf6dcce084255a12-8432b590`, three delivery commits on top of `2dc8620`:

| Commit    | What                                                                                   |
| --------- | -------------------------------------------------------------------------------------- |
| `b0ec234` | `red:` tests for the policy, the loader, the CLI, the lane, the templates, Large flows |
| `140e1ec` | the versioning policy (code, `qaConfig/qa.php`, `docs/tools/changelog.md`, CHANGELOG)  |
| `06b271f` | release workflow + approve-held-ci templates, repo copies, docs, CLAUDE/releases.md    |

A fourth commit adds this plan's `PLAN.md` (copied from the main checkout, checkboxes updated)
and this report. `CLAUDE/Plan/README.md` is untouched, as instructed.

## Design

- **`ReleaseVersionPolicy`** (`src/Changelog/`, `@api`): typed config value.
  `semanticVersioning(tagPrefix = '', firstVersion = '0.1.0')` (the default; also
  `new ReleaseVersionPolicy()`, so it can be a parameter default), `lockedMajor(int, prefix)`,
  `lockedMajorFromPhpRequirement(prefix)`. Validates the prefix (empty, or a letter then
  `[A-Za-z0-9._/-]`), the first version (plain X.Y.Z) and the major. `line(composerJson)`
  resolves it; only the PHP-line variant reads composer.json (same errors as before).
- **`ReleaseLine`** (`@api`): `isRelease`, `versionOfTag`, `tagOf`, `latestTag`, `latestVersion`,
  `next(bump, ...tags)`, `firstVersion`, `describe`. Replaces `ReleaseVersionCalculator`
  (removed). A release is `X.Y.Z` with no leading zeros (and on the locked major, if any);
  everything else (prefix mismatch, `-rc1`, `1.5`, other majors) is ignored.
- **`ReleaseBumpEnum::Major`** added; `Changed — breaking` / `Removed` ask for it. Semver: next
  major, or next minor while major is 0. Locked: next minor.
- **First release (semver, no tags)**: `0.1.0` (semver.org's initial-development start),
  configurable via `firstVersion`. Locked: `<major>.0.0` as before.
- **Config**: `QaConfigBuilder::withReleaseVersionPolicy()`, `QaConfigDto::$releaseVersionPolicy`
  (defaulted, like the other trailing changelog fields). `qaConfig/qa.php` declares
  `lockedMajorFromPhpRequirement()`; `bin/changelog-release next-version` in this repo prints
  `85.3.0`.
- **CLI**: `ReleaseVersionPolicyLoader` (`@internal`) applies `qaConfig/qa.php` to a
  defaults-seeded builder with conventional paths (no `src/`/`tests/` requirement) and a
  `NullOutput`; any load/build failure becomes `ChangelogReleaseException`
  ("cannot read the release policy from qaConfig/qa.php: ..."), exit 1. `pending-tags` prints
  `<tag> <commit>` (identical to before when there is no prefix); `notes` accepts a tag or a
  version. `next-version`/`apply` take versions as before.
- **Lane**: `ChangelogCheck::check()` and `ChangelogRangeResolver::resolve()` take the policy
  (defaulted to semver); `ChangelogTool` passes `$config->releaseVersionPolicy`. The validity line
  for a breaking section reads "the next release is breaking". The no-tag error names the
  policy's shape and first tag ("no X.Y.Z release tag … tag the first release 0.1.0").

## Workflow template

- `templates/github-actions/release.yml`: `workflow_run` on `workflows: [CI, PHP QA Pipeline, QA]`
  (the names of this repo's CI and the two shipped QA templates — `on:` filters cannot be
  expressions); job `if` = success && push && `head_branch == repository.default_branch`;
  `BRANCH` / `RELEASE_BRANCH=chore/release-<branch>` / concurrency group derived from the event;
  PHP version detected from composer.json (`for V in 8.5 …` shape, passes `versionPins`);
  every CLI call is `"$(composer config bin-dir)/changelog-release"`; tags from `pending-tags`.
- `templates/github-actions/approve-held-ci/action.yml`: new required input `workflow` (file name
  or path; `@ref` suffix and directories stripped). The release template passes
  `github.event.workflow_run.path`; `update-deps.yml` passes `ci.yml`.
- `.github/workflows/release.yml` and `.github/actions/approve-held-ci/action.yml` are byte copies;
  `GitHubActionsTemplatesTest` asserts identity plus: no branch names, CLI via bin-dir, approval
  wired to the gating workflow, action assumes no workflow file.

## Things the coordinator should know

1. **Behaviour change on the live repo**: without the `branches: [php8.5]` filter, every
   completion of `CI` / `PHP QA Pipeline` on any branch now starts a `Release` run whose job is
   skipped (cosmetic; documented in CLAUDE/releases.md). Releases still only happen for a green
   push to php8.5 (the default branch, verified via `gh api`).
2. **Chicken-and-egg for new adopters** (pre-existing behaviour, now documented): the lane fails
   on the default branch with no release tag, so CI never goes green to cut the first release.
   docs/github-actions.md tells adopters to tag the release they continue from, or `0.0.0`.
3. **Breaking PHP API** (recorded under `Changed — breaking`): `ReleaseVersionCalculator` gone,
   `ReleasedSections` constructor and `untagged()` signature changed, `ReleaseBumpEnum` gained a
   case. Under php-qa-ci's override this is still a minor (85.3.0).
4. **Hook friction**: the `R-TDD-TEST-FIRST` handler looks for tests under `/workspace/tests/...`
   (the main checkout), not the worktree's `tests/Small/...`, so it blocked writing new `src/`
   files whose tests were already committed red in the worktree. New files were written in
   `untracked/scratch/` and moved in. Worth a hooks-daemon issue.
5. Rector rewrote some escaped test strings into concatenations with `::class`; harmless.

## Verification run (targeted only)

- `phpunit --no-coverage` on the changelog / policy / config / template / version-pins tests and
  the Large changelog tests (incl. new `ReleasePolicyFlowTest`): green. The whole suite minus
  `large`: green.
- `qa -t stan -p` on `src/Changelog`, `src/Pipeline`, every touched test dir/file and
  `qaConfig/qa.php`: clean. `-t rector`/`-t fixer` applied to the same paths.
- `-t cl`, `-t ml`, `-t docsProse`, `-t vp`, `-t arch`, `-t deadCode`: pass.
- Not run: the full `bin/qa` battery, Infection (coordinator).
