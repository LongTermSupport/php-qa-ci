# CI audit: GitHub Actions in php-qa-ci, and an "actions-qa-ci" library

Read-only audit. Every workflow fact below was read from `origin/php8.5` at `a4e3283e` (the local
checkout was behind it) using `git show origin/php8.5:<path>`. Every GitHub fact comes from `gh api` or `gh run list` on
2026-10-21. Copies of the files read are in `untracked/scratch/ciaudit/`.

---

## Part 1: is QA running twice on a PR?

Yes. Each push to a PR branch runs the full pipeline twice, from two workflows with different
triggers. The duplication is also built into the shipped consumer template, which double-runs in
consumer projects as well.

### 1.1 What each workflow triggers on

| Workflow file     | `name:`               | Triggers                                                                                                                                              | Jobs (check names)                                                                                   |
| ----------------- | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `ci.yml`          | `CI`                  | `push` to `php8.5`; `pull_request` to `php8.5`                                                                                                        | `QA Pipeline`: checkout `fetch-depth: 0`, setup-php 8.5 + xdebug, `composer install`, `bash ci.bash` |
| `qa.yml`          | `PHP QA Pipeline`     | `push` to `main, master, develop, feature/**, bugfix/**, chore/**, hotfix/**, fix/**`; `pull_request` to `main, master, develop`; `workflow_dispatch` | `Detect PHP Version`, `PHP QA (8.5)`, `Coverage Report` (pull_request only)                          |
| `release.yml`     | `Release`             | `workflow_run` of `[CI, PHP QA Pipeline, QA]`, `completed`                                                                                            | `Release`, gated by `if:` to success + `event == push` + default branch                              |
| `update-deps.yml` | `Update Dependencies` | `schedule` `17 3 * * *`; `workflow_dispatch`                                                                                                          | `Update Dependencies & Verify QA` (also runs `ci.bash`)                                              |

### 1.2 Which runs a typical change produces

**A push to `bugfix/x` with an open PR to `php8.5`:**

- `CI` / `QA Pipeline`, from `pull_request` (`synchronize`). It is the required check.
- `PHP QA Pipeline` / `Detect PHP Version` + `PHP QA (8.5)`, from `push`, because `bugfix/**` is
  in qa.yml's push list. `Coverage Report` is skipped: it is `pull_request`-only, and qa.yml has no
  `pull_request` trigger for `php8.5`, so that job never runs in this repository.
- `Release` twice, once on each completion. Both are skipped by the job's `if:`.

PR #291 (`bugfix/log-archive-name-too-long`) confirms this from `gh pr view 291 --json statusCheckRollup`
and `gh run list --branch …`. Head `b57e6413` has two runs: `CI pull_request` (09:39–09:43, failure)
and `PHP QA Pipeline push` (09:38–09:45, failure).

**A push to `php8.5` (a merge):** only `CI` (push). `qa.yml` does not list `php8.5`. `Release` then
runs off CI's completion.

**A push to a branch with no PR:** only `PHP QA Pipeline` (push).

**Cost** (`gh run list --limit 60` per workflow):

- `ci.yml`: median about 4 min on both `pull_request` (48 runs) and `push` (12 runs).
- `qa.yml`: median about 6 min (59 push runs). It is slower because coverage is on: `phpUnitCoverage`
  defaults to true (`QaConfigBuilder.php:151`), while `ci.bash` exports `phpUnitCoverage=0`.
- `release.yml`: 51 of 60 runs were `skipped`. These are the `workflow_run` deliveries from the PR
  runs.
- The repository is **public**, so the minutes are not billed. `gh api …/actions/runs/<id>/timing`
  shows `billable.UBUNTU.total_ms: 0`.

The real costs are these:

- Runner concurrency: the free plan allows 20 concurrent jobs per account, and each PR push takes 3
  jobs plus 2 release stubs.
- Two red runs per defect. Both ran red on #291.
- A second, slower result that nothing requires.
- Noise in the Actions tab.

The two runs are not the same check:

| Setting    | `ci.yml` run                                                   | `qa.yml` run                               |
| ---------- | -------------------------------------------------------------- | ------------------------------------------ |
| Commit     | the PR merge ref (`refs/pull/N/merge`)                         | the branch tip                             |
| Coverage   | off                                                            | on                                         |
| Extensions | `json, tokenizer, mbstring`                                    | 15 extensions                              |
| Caching    | none                                                           | composer download cache and `var/qa/cache` |
| Read-only  | auto-enabled by `GITHUB_ACTIONS` (`EnvironmentReader.php:115`) | `QA_READONLY=1` set explicitly             |
| Entry      | `ci.bash`                                                      | `bin/qa` directly                          |

Nothing in qa.yml's run is required. The only thing it uniquely proves is that the consumer
template still works, which is its stated purpose; see 1.3.

### 1.3 qa.yml is the consumer template, run as a dogfood check

- `diff .github/workflows/qa.yml templates/github-actions/php-qa-ci.yml` finds the two files
  **byte-identical**.
- This identity is **enforced by the versionPins lane** (`phpqaci.versionPins`):
  - `src/VersionPins/VersionPinsCheck.php:36-38` hard-codes `SHIPPED_TEMPLATE = /templates/github-actions/php-qa-ci.yml` and `RUN_WORKFLOW = /.github/workflows/qa.yml`.
  - Lines 147-155 fail when both exist and differ.
  - `tests/Small/VersionPins/VersionPinsCheckTest.php:60` asserts that message.
  - `docs/tools/versionPins.md:41-43` documents it as a self-check of this repository.
  - Renaming or deleting `.github/workflows/qa.yml` silently switches the identity check off,
    because it only runs when both files exist. The lane does not fail.
- `tests/Small/GitHubActionsTemplatesTest.php` docblock: "This repository runs the pipeline
  template on itself, and that run failed on every push for exactly this reason without failing
  anything else". That is the evidence the dogfood run has caught template defects (commit
  `fc193bc9`).
- The same test, `thePipelineTemplateRunsOnEveryBranchPrefixThePolicyAllows` (line 72-80), **requires**
  the template's push trigger to list every `BranchNamePolicyDecision::DEFAULT_PREFIXES` entry. That
  test is the defence that keeps the feature-branch push trigger, which is what produces the second
  run. Changing it is an Owner decision.
- `scripts/install-github-actions.bash:56-59` seeds `templates/github-actions/php-qa-ci.yml` into
  a consumer's `.github/workflows/qa.yml` once, and never re-syncs it. It does not read this
  repository's own `qa.yml`.
- `.claude/rules/phpqaci-releases.md` path-scopes the release rule to `ci.yml`, `qa.yml` and
  `release.yml`.
- `qa-autofix.yml` (`name: QA`) is a different design. It triggers on all `push` and
  `pull_request` events and has a job-level guard so that only PRs and pushes to the default branch run
  the gate. It has `concurrency` with `cancel-in-progress`, `permissions: contents: read` and
  `coverage: none`. Its gate runs only `allCS` and `allStatic`, with no tests. It is not
  dogfooded here.

**This is a consumer defect, not only a local one.** `php-qa-ci.yml` pushes on `feature/**` etc.
*and* runs `pull_request` to `main`. A consumer whose default branch is `main` therefore gets two
full runs (push and pull_request) for every push to a PR branch. That is the same duplication, in
every consumer that used the installer.

### 1.4 What release.yml actually requires

`release.yml` equals `templates/github-actions/release.yml`, enforced by
`GitHubActionsTemplatesTest::thisRepositoryReleasesThroughTheShippedReleaseWorkflow`.

- It starts on the completion of any workflow *named* `CI`, `PHP QA Pipeline` or `QA`.
- The job runs only if that run's `conclusion == success`, `event == push` and `head_branch == default_branch` (`php8.5`).
- It then requires the following, in the step "Act only on the verified commit":
  - `HEAD` of `php8.5` is still the verified SHA.
  - The newest run of **every** `GATING_WORKFLOWS` name that ran on that SHA with `event=push` is
    `completed`/`success`. Matching is by workflow name, through `/actions/runs?head_sha=…&event=push`.
- On `php8.5` today only `CI` runs on push, so **CI's green push run is the sole gate**.
- Removing `qa.yml`:
  - Release: no effect. It never runs on a `php8.5` push, and its PR-branch completions are
    already skipped.
  - Side effects: the versionPins identity check goes quiet (see 1.3), and the template is no
    longer dogfooded.
- Removing `ci.yml` without replacement **breaks** three things:
  - Nothing runs on a push to `php8.5`, so no release ever starts.
  - The required `QA Pipeline` check never reports, so every PR is blocked.
  - `update-deps.yml:224` passes `workflow: ci.yml` to approve-held-ci. That action does `gh run list --workflow ci.yml --event pull_request`, so it would time out after 30×10 s and fail.
- `release.yml` passes `workflow: ${{ github.event.workflow_run.path }}` to approve-held-ci, so it
  follows whichever workflow gated the release. The action approves runs of *that* workflow from
  the `pull_request` event only, so the gating workflow must also run on `pull_request` and produce
  the required check.

### 1.5 Branch protection and required checks

- `gh api repos/LongTermSupport/php-qa-ci/branches/php8.5/protection` returns **404 Not Found**.
  This account has `admin: false` (`gh api repos/… --jq .permissions`), and the endpoint is
  admin-only, so a 404 here does not mean unprotected.
- `gh api repos/LongTermSupport/php-qa-ci/branches/php8.5 --jq .protection` (readable) gives:
  - `protected: true`
  - `required_status_checks.contexts: ["QA Pipeline"]`, app_id 15368 (GitHub Actions)
  - `enforcement_level: non_admins`
- Strict mode, reviews and conversation resolution are not visible without admin. `CLAUDE/releases.md`
  claims strict, 0 reviews and resolved conversations: **unverified**.
- Rulesets (`gh api …/rulesets/<id>`): `protect` and `protect-default-branch` both target
  `~DEFAULT_BRANCH`.
  - They cover deletion, non_fast_forward, and a `pull_request` rule: 0 approvals, merge methods
    `merge` and `rebase`, `require_extra_approval_for_unattributed_changes: true`.
  - **Neither ruleset requires a status check.** The only required check is the classic `QA Pipeline`.
  - `protect-default-branch` duplicates two of `protect`'s rules.
- `php8.4` requires `QA Pipeline` and `ShellCheck (severity=warning)`.
- `gh api …/actions/permissions/workflow` returned **403**, so the repository's default
  `GITHUB_TOKEN` permission (read or write) is **unverified**. The template comment tells consumers
  to choose "Read and write".
- **Defect found:** `scripts/setup-branch-protection.bash:166-168` still sets `OUR_CONTEXTS = ["QA Pipeline", "ShellCheck (severity=warning)"]` and says they are "Job names from
  .github/workflows/ci.yml". On php8.5, ci.yml has no ShellCheck job, which the comment at
  `ci.yml:10-13` confirms. Running the script would add a required context that never reports, and
  line 167 warns that this "would block every merge forever". Under DBF this needs a detector, for
  example a test that every `OUR_CONTEXTS` entry is a job name in `ci.yml`. It was not fixed here.

### 1.6 Other pragmatic issues

| #   | Issue                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | Where                                               | Severity                         |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- | -------------------------------- |
| a   | **No `concurrency`.** A superseded push to a PR keeps both full pipelines running. Only `release.yml` (serialised, correctly not cancelling) and `qa-autofix.yml` have one.                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | ci.yml, qa.yml/php-qa-ci.yml, update-deps.yml       | waste                            |
| b   | **No `permissions:` block.** The token gets the repository default, unverified (403). If it is "read and write", every lane, including `composer install` of third-party plugins, runs with a write token on push events. `release.yml` (`{}` + per-job) and `qa-autofix.yml` (`contents: read` + per-job) do this correctly.                                                                                                                                                                                                                                                                                                                           | ci.yml, qa.yml/php-qa-ci.yml                        | security (least privilege)       |
| c   | **`update-deps.yml` grants `actions/contents/issues/pull-requests: write` workflow-wide.** That includes the steps that run `composer update` (third-party plugins and scripts execute) and the full QA run. A compromised dependency gets a repo-write token. Splitting into a read-only build/QA job and a write job that only opens the PR (passing the diff as an artifact) limits this.                                                                                                                                                                                                                                                            | update-deps.yml:10-14                               | security                         |
| d   | **Actions pinned by mutable tag, not SHA.** `actions/checkout@v7`, `shivammathur/setup-php@v2`, `actions/cache@v6`, `actions/upload-artifact@v7`, `actions/download-artifact@v8`, `actions/github-script@v9`, `peter-evans/create-pull-request@v8` (runs with contents/PR write), `marocchino/sticky-pull-request-comment@v3`, `irongut/CodeCoverageSummary@v1.3.0`. There is no Dependabot config (`.github/` has no `dependabot.yml`), and action bumps are manual (commit `126beb54`). SHA pinning needs an updater, either Dependabot `github-actions` or pinact/ratchet in update-deps.                                                            | all                                                 | supply chain                     |
| e   | **ci.yml has no caching.** There is no composer download cache and no `var/qa/cache` (the PHPStan result cache etc.). qa.yml has both.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | ci.yml                                              | waste                            |
| f   | **ci.yml loads xdebug (`coverage: xdebug`), but `ci.bash` sets `phpUnitCoverage=0`.** xdebug is loaded for the whole run and never used. Whether bin/qa disables it per child is unverified.                                                                                                                                                                                                                                                                                                                                                                                                                                                            | ci.yml:32, ci.bash                                  | waste (probable)                 |
| g   | **`update-deps.yml` updates twice.** It runs `composer update` twice and `tool-install.bash update` twice (once as post-update-cmd, once explicitly). The comments justify both.                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | update-deps.yml:54-88                               | minor waste, documented          |
| h   | **PHP version detection by substring.** The first matching hand-written list entry wins (`*"8.5"*` … `*"8.0"*`), so `^8.4 \|\| ^8.5` selects 8.5 (the newest, not the lowest supported). `>=8.1` selects 8.1. A future `8.6` falls to the default. There are three copies, written two ways: an if/elif chain in php-qa-ci.yml, and a `for` loop in qa-autofix.yml (twice) and release.yml. versionPins checks the lists contain the required version, which is a defence but not a fix. Since `composer.lock` already pins the platform, `composer config platform.php`, or reading `composer.lock`'s `platform-overrides`/`platform`, would be exact. | qa.yml:130-161, qa-autofix.yml, release.yml:111-120 | correctness, low                 |
| i   | **`Detect PHP Version` is a whole job.** It spends a runner start, a sparse checkout and a check name to run one `jq`. As a step in the `qa` job it costs about 5 s. It also makes the QA check name dynamic (`PHP QA (8.5)`), which is a poor required check: it changes name when the PHP line moves, and branch protection then waits for ever on the old name.                                                                                                                                                                                                                                                                                      | qa.yml                                              | waste, fragility                 |
| j   | **The `Coverage Report` job** never runs here (no `pull_request` trigger for php8.5). In consumers:<br>- it needs `pull-requests: write` for the sticky comment, which a fork PR never has, so it fails on forks;<br>- `irongut/CodeCoverageSummary` is a Docker action whose last tag is v1.3.0 (maintenance unverified);<br>- it adds a third check name the template tells consumers to require.<br>Better: drop it, or make it optional and `continue-on-error`. A coverage *gate*, if wanted, belongs in bin/qa, not a comment bot.                                                                                                                | qa.yml:313-342                                      | low value                        |
| k   | **Template injection, flagged by zizmor (Part 2).**<br>- `"$QA" -t ${{ github.event.inputs.qa_tools }}` (qa.yml:237-239) interpolates an input into the shell. It is a `choice` input, so the exposure is low, but it should go through `env:`.<br>- `qa-autofix.yml` runs `git push origin "HEAD:${{ github.head_ref }}"`, and `head_ref` is the PR author's branch name.                                                                                                                                                                                                                                                                              | qa.yml, qa-autofix.yml                              | security (low/medium)            |
| l   | **The auto-commit guard `!contains(github.event.repository.default_branch, github.ref)` is inert.** It compares `php8.5` against `refs/heads/php8.5`, so it is always true. The shell check inside the step is the real guard.                                                                                                                                                                                                                                                                                                                                                                                                                          | qa.yml:277                                          | correctness, low                 |
| m   | **`release.yml` starts on every completion of all three named workflows on every branch.** 51 of 60 runs are skipped stubs. A `branches:` filter on `workflow_run` (supported, but it filters `head_branch` with a literal) cannot name "the default branch" in a template. That cost is the price of a branch-agnostic template; it is free for a public repo.                                                                                                                                                                                                                                                                                         | release.yml                                         | noise only                       |
| n   | **`update-deps` is often red.** 29 of 44 scheduled runs failed (`gh run list`), but the last 3 runs succeeded.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | update-deps.yml                                     | tracked by `update-deps-failure` |

### 1.7 Options

**Option A: one workflow that dogfoods the shipped template, with the template fixed (recommended).**

Make `templates/github-actions/php-qa-ci.yml` branch-agnostic, the way `qa-autofix.yml` already is:

- Triggers: `on: push` (all), `pull_request` (all) and `workflow_dispatch`.
- A job-level `if:` runs on `pull_request`, `workflow_dispatch`, or a push where `github.ref_name == github.event.repository.default_branch`.
- `concurrency: {group: qa-${{ github.head_ref || github.ref }}, cancel-in-progress: true}`.
- `permissions: contents: read`, plus `contents: write` only when `AUTO_COMMIT_FIXES` is on.
- Fold `Detect PHP Version` into a step.
- A **static** job name, for example `QA Pipeline`, so the required check stays the same across PHP lines.
- `Coverage Report` dropped or made opt-in.

Then delete `ci.yml` and let `.github/workflows/qa.yml` stay the identical copy.

ci.yml's extras are already covered or can be:

- `fetch-depth: 0`: already in the template.
- `ci.bash`: its only effects are `phpqaQuickTests=0`, `phpUnitQuickTests=0`, `phpUnitCoverage=0`,
  `CI=true` and a `tee` to `var/qa/ci.log`. These map to template `env:`, the existing artifact
  upload, or to `qaConfig/qa.php`, which is the right home for repository policy.
- `update-deps.yml` keeps calling `ci.bash` locally.

Required changes:

1. Branch protection: the required check becomes the template's static job name. If it is named
   `QA Pipeline`, nothing changes.
2. `update-deps.yml:224` changes `workflow: ci.yml` to `workflow: qa.yml`.
3. `release.yml` needs no change, because `PHP QA Pipeline` is already in `workflows:` and
   `GATING_WORKFLOWS`. Optionally drop `CI` from the list, but that is a template change affecting
   consumers who use a `CI` workflow, so keep it.
4. `GitHubActionsTemplatesTest::thePipelineTemplateRunsOnEveryBranchPrefixThePolicyAllows` must be
   rewritten into "runs on every pull_request and on a push to the default branch". **Owner
   decision**, because it replaces a standing defence.
5. `scripts/setup-branch-protection.bash` `OUR_CONTEXTS` and the docs that name ci.yml (`docs/ci.md`,
   `CLAUDE/releases.md`, `docs/github-actions.md` branch-protection section, the `.claude/rules`
   path list) need updating.
6. A `CHANGELOG` entry: the template change is consumer-visible (`templates/` is watched), and
   a consumer's required check names change. That makes it `Changed — breaking` if the job names
   change.

- Trade-off, for: one run per PR push. Every php-qa-ci PR then proves the exact file consumers
  receive, and consumers lose their double run too.
- Trade-off, against: it is the larger change. It touches a defence test and a consumer-visible
  contract (check names). A feature-branch push with no PR yields an empty skipped run (cosmetic,
  as in qa-autofix). The template carries this repository's policy, so anything ci.bash does that
  a consumer should not get needs `qaConfig/qa.php`, not the template.

**Option B: keep ci.yml, and stop qa.yml triggering on feature-branch pushes here.**

This is not possible while qa.yml must be byte-identical to the template. Any trigger edit in
`.github/workflows/qa.yml` fails the versionPins identity check. The variants:

- **B1.** Delete `.github/workflows/qa.yml`. The identity check goes inert, and nothing dogfoods the
  template. You lose the defence that caught `fc193bc9`'s bin-dir bug. Smallest change, but a
  defence lost, so an Owner decision.
- **B2.** Keep qa.yml but add `workflow_dispatch`/`schedule`-only dogfooding through a reusable
  wrapper. This needs the template to support `workflow_call`. More moving parts.
- **B3.** Change the template's push list to exclude feature prefixes. This again rewrites the
  branch-prefix defence test, so it is Option A's cost without its benefits.

**Option C: keep both, and make the duplication cheap.**

Add `concurrency` with cancel-in-progress and caching to ci.yml, and accept two runs.

- Trade-off: the smallest change, but it fixes nothing about the duplication or the consumer
  double run.

**Recommendation: Option A.** Do it on one branch:

- template fix plus test change (Owner sign-off on the test);
- delete ci.yml;
- update-deps `workflow:` input;
- branch protection kept on a static `QA Pipeline` name;
- docs;
- changelog entry.

Fold in items a, b, d (SHA pins with an updater), h, i, j and k from 1.6. Separately, and under
DBF:

- add the setup-branch-protection detector and fix;
- add the update-deps permission split (c).

---

## Part 2: an "actions-qa-ci" library

The full survey is in [actions-tool-survey.md](actions-tool-survey.md), with a URL or command
output cited for each fact. Its raw tool outputs (`al-wf.json`, `al-tpl.json`, `zz-*.json`) and the
tools themselves were left untracked in the auditing checkout. The essentials:

**Tools.**

- **actionlint** (Go binary, MIT, v1.7.12):
  - Stable "kinds", 17 of them, documented as anchors on one page.
  - Shellcheck/pyflakes run over `run:` scripts.
  - JSON output via template. SARIF only via a template file.
  - Suppression is a regex only (`-ignore`, or `actionlint.yaml`), with no reason field.
- **zizmor** (Rust, PyPI/binary, MIT, v1.31.1, very active):
  - About 40 audits, each with a stable kebab-case id and a doc anchor at `docs.zizmor.sh/audits/#<id>`.
  - Output: JSON, SARIF and GitHub annotations.
  - Inline `# zizmor: ignore[id] reason` (reason optional), plus `zizmor.yml` ignores. `--no-ignores` audits them.
  - Online audits need a token.
  - It is the best fit for DBF identifiers.
- **poutine** (Go, Apache-2.0):
  - Stable ids with a per-rule page each. SARIF output.
  - `skip:` config with no reason. Supports custom Rego rules.
- **octoscan** (GPL-3.0): no per-rule docs.
- **ghalint** (MIT): numbered policy docs, no SARIF. It is being ported to lintnet.
- **pinact** (MIT, v5): pins to SHA, has a `--check` mode, SARIF output, coded docs. Best as the
  fixer for unpinned-uses.
- **ratchet** (Apache-2.0): no rule ids.
- **frizbee**: no structured output.
- **Scorecard**: an API-level posture check, a scheduled lane rather than a gate.
- **Legitify**: last release 2024-07.
- **harden-runner**: runtime egress monitoring, not a linter. Free only for public repos on hosted runners.
- **secure-repo**: AGPL.
- **checkov** has CKV_GHA\_\* checks. Semgrep's GHA rules carry a redistribution-restricting licence.
  **CodeQL** has an `actions` language with per-query help pages.
- **gato-x**: an attack tool.

**Local runs (offline).**

- actionlint 1.7.12, checksum verified: 14 findings.
  - The empty `choice` option `''` in qa.yml/php-qa-ci.yml line 87 is a real schema error.
  - SC2086/SC2129 infos.
  - `github.head_ref` used in an inline script in `qa-autofix.yml` (line 254), which is item k above.
  - ci.yml and release.yml are clean.
  - Results are the same with the vendored shellcheck 0.11.0.
- zizmor 1.31.1: 40 findings in `.github/`, 36 in `templates/`. The two sets largely repeat,
  because the templates are copies.
  - `unpinned-uses` 21+18
  - `template-injection` 5+6
  - `excessive-permissions` 5+4
  - `artipacked` (no `persist-credentials: false`) 6+6
  - `dangerous-triggers` on release.yml's `workflow_run` (by design; a justified-suppression candidate)
  - `self-repository`
  - The pedantic persona adds `concurrency-limits` and `undocumented-permissions`.
  - The online audits (impostor-commit, known-vulnerable-actions, ref-confusion) were not run, because no token was in the environment.
- These corroborate items a, b, d and k in 1.6.

**Sketch of an actions-qa-ci in DBF terms.**

- **Pinned toolchain.** Version-file and sha256-pinned binaries for actionlint, zizmor and poutine,
  the way `src/ShellCheck/*` and `bin/shellcheck-install` pin `vendor-bin/shellcheck`. actionlint
  reuses the pinned shellcheck via `-shellcheck`. zizmor runs `--offline` locally and online in CI.
- **Identifiers.** `actionsqaci.<tool>.<upstream-id>`, for example
  `actionsqaci.zizmor.unpinned-uses` or `actionsqaci.actionlint.shellcheck.SC2086`. Each gets a thin
  local page (the correct construction) that links to the upstream anchor.
- **One owner per hazard class.** For example, injection belongs to zizmor and not to actionlint
  `expression`, so each hazard has exactly one identifier.
- **First-party lanes:**
  - template-drift, generalising the versionPins identity check;
  - workflow coverage, because zizmor skips files outside `.github/`;
  - runner-PHP pins.
- **Listing and lookup.** A `rules` listing and an offline `rule-doc`.
- **Justification lane.**
  - Suppressions are allowed only in `zizmor.yml` and `actionlint.yaml`, each with a non-generic
    comment, reusing the generic-reason detector of the phpstanIgnoreJustification lane.
  - Inline ignores are banned or must carry a reason.
  - CLI `-ignore` is forbidden ("not a tool proxy").
- **Entry point and output.** One entry point, `actions-qa [-t lane]`, producing a merged SARIF
  with a `category` per tool.

**Reusable from php-qa-ci:**

- `RuleDocResolver`, and the `rules`/`rule-doc` shapes and the identifier-index format;
- the agent-summary generator;
- the generic-reason detector from the justification lane;
- the pinned-binary installer;
- `src/Changelog/*` + `release.yml`;
- the branch policy and the docs lanes (markdownLinks, docsProse, markdownFormat);
- skills deployment.

**PHP-specific:** the PHPStan rules, the composer plugins, rector, php-cs-fixer, phpunit,
infection, opcache, phplint, psr4 and Arkitect.

**Open question for the Owner: the language.** A PHP entry point gives maximum reuse and fits the
"PHP for logic" ruling, but every non-PHP repo then needs PHP 8.5. A Go or Python tool installs
anywhere but re-implements the DBF machinery. A middle path:

- keep the DBF data language-neutral (JSON index, Markdown pages, SARIF finding schema);
- ship a PHAR plus a container/composite action;
- php-qa-ci consumes it as a lane.

Prior context: `CLAUDE/Plan/Completed/00004-qa-ecosystem-lanes/PLAN.md:35` recorded actionlint as
an explicit non-goal ("Non-PHP toolchains").

Unverified items are marked as such in `ci-audit-part2.md`:

- actionlint docker/brew distribution;
- zizmor release binaries and the action's name;
- CodeQL `actions` GA status;
- checkov inline skip for GHA;
- Scorecard SARIF;
- the online zizmor audits.
