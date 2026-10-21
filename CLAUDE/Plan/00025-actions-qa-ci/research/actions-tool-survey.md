# CI audit part 2: GitHub Actions QA tooling survey, local actionlint/zizmor run, actions-qa-ci sketch

Researched 2026-10-21. Repo metadata (stars, licence, latest release) was taken from the GitHub REST API
(`gh api repos/<r>` and `repos/<r>/releases/latest`) on that date; other facts were taken from the URL
cited beside them. Anything marked **[unverified]** was not confirmed against a primary source.

---

## 1. Tool survey

Columns: stars / licence / latest release (date) come from the GitHub API.

### Static linters / security scanners for workflow files

| Tool                 | What it checks                                                                                                                                                                                                                   | Lang / distribution                                                                                                                                        | Licence                               | Latest release, stars                    | Stable ids + per-rule docs                                                                                                                                                                                                                                                                                                                                                                                                             | Output                                                                                                                                                                                                    | Suppression                                                                                                                                                                                                                                                                                                                    |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **actionlint**       | Workflow syntax, expression type checks, `needs`, matrix, events, glob, runner labels, permissions, deprecated commands, credentials, untrusted-input in `run:`, plus shellcheck and pyflakes over `run:` scripts                | Go; single static binary per platform with a `checksums.txt` per release; also Docker and brew **[unverified: docker/brew not re-checked]**                | MIT                                   | v1.7.12 (2026-03-30), 4343               | Yes. 17 "kinds" (list printed locally via the `allKinds` template function): action, credentials, deprecated-commands, env-var, events, expression, glob, id, if-cond, job-needs, matrix, permissions, runner-label, shell-name, shellcheck, syntax-check, workflow-call. Docs are sections of one page, `docs/checks.md#<anchor>`; the anchors do not map 1:1 to kinds (https://github.com/rhysd/actionlint/blob/main/docs/checks.md) | Go-template `-format`; `{{json .}}` gives JSON; SARIF only through a template that ships in the repo (`testdata/format/sarif_template.txt`) (https://github.com/rhysd/actionlint/blob/main/docs/usage.md) | No inline comments. `-ignore <RE2 regex on message>` (repeatable); `.github/actionlint.yaml` `paths: <glob>: ignore: [regex]`. No reason field (https://github.com/rhysd/actionlint/blob/main/docs/config.md). The shellcheck findings embed `SCxxxx`, so a regex can target one code                                          |
| **zizmor**           | Security audits: template-injection, unpinned-uses, excessive-permissions, dangerous-triggers, artipacked, cache-poisoning, github-env, impostor-commit, known-vulnerable-actions, ref-confusion, bot-conditions, and ~40 in all | Rust; PyPI wheel (`pip install zizmor`), cargo, brew, Docker, official action `zizmorcore/zizmor-action` **[unverified: action/Docker names not fetched]** | MIT                                   | v1.31.1 (2026-10-15), 6743; very active  | Yes. Kebab-case audit ids, one doc anchor each: `https://docs.zizmor.sh/audits/#<id>`. Each anchor gives metadata (introduced-in, offline, auto-fix, configurable) and a remediation (https://docs.zizmor.sh/audits/)                                                                                                                                                                                                                  | plain, `json`/`json-v1`, `sarif`, `github` annotations. Exit codes 11–14 map to the highest severity (https://docs.zizmor.sh/usage/)                                                                      | Inline `# zizmor: ignore[id,id2] free-text reason` (a reason is allowed but optional). Config `zizmor.yml` `rules.<id>.ignore: [file.yml:line:col]` (no reason field, but YAML comments are possible). `--no-ignores` audits the suppressions. Personas regular/pedantic/auditor; `--min-severity`/`--min-confidence`; `--fix` |
| **poutine**          | Pipeline supply-chain: untrusted_checkout_exec, injection, job_all_secrets, pr_runs_on_self_hosted, unpinnable_action, known vulns, debug_enabled, confused_deputy_auto_merge, etc. GHA, GitLab, Azure DevOps, Tekton            | Go; binary, brew, Docker `ghcr.io/boostsecurityio/poutine`, action, pre-commit (https://github.com/boostsecurityio/poutine)                                | Apache-2.0                            | v1.1.6 (2026-05-22), 527; pushed 2026-10 | Yes, snake_case ids; `https://boostsecurityio.github.io/poutine/rules/<rule_id>/` (https://boostsecurityio.github.io/poutine/)                                                                                                                                                                                                                                                                                                         | pretty, json, sarif                                                                                                                                                                                       | `.poutine.yml` `skip:` filtered by rule/job/level/path/purl/osv_id; `--skip`. No documented reason field. Custom Rego rules                                                                                                                                                                                                    |
| **octoscan**         | Expression injection, dangerous checkout/write/artefact, bot-check, repo-jacking, known vulnerability, local-action, plus actionlint's shellcheck/credentials (17 rules)                                                         | Go; build from source, Docker, action (https://github.com/synacktiv/octoscan)                                                                              | GPL-3.0                               | v0.1.7 (2026-03-30), 274                 | Rule names (`--list-rules`); no per-rule doc pages found                                                                                                                                                                                                                                                                                                                                                                               | json, sarif, Go template                                                                                                                                                                                  | `--ignore` regex, `--disable-rules`/`--enable-rules`, config file. No reason                                                                                                                                                                                                                                                   |
| **ghalint**          | Policy: job permissions, read-all/write-all, inherit secrets, full-SHA pins, persist-credentials false, timeout-minutes, app-token scoping (13 policies)                                                                         | Go binary; action **[install methods unverified]**                                                                                                         | MIT                                   | v1.5.6 (2026-05-01), 267                 | Yes: numbered + named, `docs/policies/NNN.md` (https://github.com/suzuki-shunsuke/ghalint). Being ported to lintnet (Jsonnet) per README                                                                                                                                                                                                                                                                                               | Log lines only; no JSON/SARIF documented                                                                                                                                                                  | `ghalint.yaml` `excludes:` (policy_name + workflow/job/action/step scope); only 4 policies excludable; no reason field                                                                                                                                                                                                         |
| **CodeQL (actions)** | Taint/data-flow: code/envvar/envpath injection, untrusted checkout (+TOCTOU), artifact/cache poisoning, secrets exposure, missing permissions, unpinned tag, vulnerable action. 17 default + 6 security-extended                 | CodeQL CLI/action (`languages: actions`)                                                                                                                   | Queries MIT; CLI under GitHub's terms | repo 10223 stars                         | Yes, `https://codeql.github.com/codeql-query-help/actions/<slug>/` (https://docs.github.com/en/code-security/code-scanning/managing-your-code-scanning-configuration/actions-built-in-queries). GA status not stated on that page **[unverified]**                                                                                                                                                                                     | SARIF (native)                                                                                                                                                                                            | Dismissal in the code-scanning UI (a reason is selected); no inline YAML suppression **[unverified for actions]**                                                                                                                                                                                                              |
| **checkov** (GHA)    | CKV_GHA_1..7 (unsecure commands, shell injection, curl with secrets, netcat, cosign sign/SBOM, empty dispatch inputs), CKV2_GHA_1 (write-all)                                                                                    | Python; pip, Docker                                                                                                                                        | Apache-2.0                            | 3.3.30 (2026-10-20), 9088                | Yes, CKV ids; one policy-index page (https://www.checkov.io/5.Policy%20Index/github_actions.html)                                                                                                                                                                                                                                                                                                                                      | cli, json, sarif, etc.                                                                                                                                                                                    | `checkov:skip=<ID>:<reason>` (reason allowed) documented for Terraform/CFN/Docker etc.; **not documented for GHA files**; `--skip-check` (https://www.checkov.io/2.Basics/Suppressing%20and%20Skipping%20Policies.html)                                                                                                        |
| **Semgrep** rules    | `p/github-actions` ruleset, e.g. `yaml.github-actions.security.run-shell-injection.run-shell-injection`                                                                                                                          | Semgrep CLI (pip/brew/Docker)                                                                                                                              | Rules: Commons Clause + LGPL-2.1      | semgrep-rules 1279 stars, no releases    | Yes, dotted ids; `https://registry.semgrep.dev/rule/<id>` (https://registry.semgrep.dev/rule/yaml.github-actions.security.run-shell-injection.run-shell-injection)                                                                                                                                                                                                                                                                     | json, sarif                                                                                                                                                                                               | `# nosemgrep: <id>` inline **[unverified for YAML]**. The rules' licence limits redistribution                                                                                                                                                                                                                                 |

### Pinning tools (fixers / checkers for `uses:` refs)

| Tool        | Purpose                                                                                                                                                                                  | Dist                                 | Licence    | Latest, stars             | Ids / output                                                 | Suppression                                               |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ | ---------- | ------------------------- | ------------------------------------------------------------ | --------------------------------------------------------- |
| **pinact**  | Pins actions/reusable workflows to SHA + `# vX` comment; `--update`; `run --check` fails CI; `--verify-comment`; min release age (https://github.com/suzuki-shunsuke/pinact)             | Go binary, action                    | MIT        | v5.0.0 (2026-09-12), 1233 | Coded docs (`docs/codes/001.md`); SARIF via `--format sarif` | `.pinact.yaml` `ignore_actions`; no reason documented     |
| **ratchet** | pin/unpin/update/upgrade/lint for GHA, containers, CircleCI, GitLab, Cloud Build, Drone, Tekton; `# ratchet:<constraint>` comments; `-bake-delay` (https://github.com/sethvargo/ratchet) | Static binary, container, action     | Apache-2.0 | v0.12.0 (2026-07-18), 970 | No rule ids; `lint` exits non-zero                           | Inline `# ratchet:exclude` (no reason; no spaces allowed) |
| **frizbee** | Rewrites action refs and image tags to digests; dry-run with a non-zero exit (https://github.com/stacklok/frizbee)                                                                       | Go, brew, winget, action, Go library | Apache-2.0 | v0.1.11 (2026-08-14), 186 | None; no structured output documented                        | `.frizbee.yml` excludes (actions, branches, images, tags) |

### Posture / runtime / offensive (out of the static-lint lane)

| Tool                           | Nature                                                                                                                                                                                                                                                                                                                             | Licence / latest                                                                   | Relevance                                           |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- | --------------------------------------------------- |
| **OpenSSF Scorecard**          | Repo-level score through the API; GHA-relevant checks are Pinned-Dependencies, Token-Permissions, Dangerous-Workflow. Docs at `docs/checks.md#<check-name>`. No per-finding suppression documented; needs network/token (https://github.com/ossf/scorecard/blob/main/docs/checks.md). The action emits SARIF **[unverified here]** | Apache-2.0; v5.6.0 (2026-10-12), 5771                                              | A scheduled posture lane, not a pre-push gate       |
| **Legitify**                   | API posture for org/repo/Actions/runner-group settings; snake_case policy ids; json/sarif; docs legitify.dev (https://github.com/Legit-Labs/legitify)                                                                                                                                                                              | Apache-2.0; **last release v1.0.11, 2024-07-09** (commits to 2026-08)              | Stale releases; settings posture, not workflow code |
| **StepSecurity harden-runner** | Runtime EDR-style agent: egress audit/block, file integrity, process monitoring. Community tier covers public repos on GitHub-hosted Linux only; private repos and self-hosted need Enterprise (https://github.com/step-security/harden-runner). No stable finding ids documented                                                  | Apache-2.0; v2.22.2 (2026-10-19), 1289                                             | Complements the static checks; not a linter         |
| **StepSecurity secure-repo**   | Backend of the "secure-workflow" fixer: pins actions, adds permissions and harden-runner, opened as a PR via app.stepsecurity.io                                                                                                                                                                                                   | **AGPL-3.0**; v1.12.9 (2026-09-10), 333                                            | AGPL; a SaaS-oriented fixer                         |
| **gato-x**                     | Offensive: search, enumerate (Pwn Request, injection, TOCTOU, self-hosted runners), attack; needs a PAT; JSON output (https://github.com/AdnaneKhan/gato-x)                                                                                                                                                                        | Apache-2.0; v1.4.0 (2026-04-25), 593. The original praetorian-inc/gato is archived | Red-team tool, not a CI gate                        |

---

## 2. Local runs

Tools sit under `/workspace/untracked/tools/` (untracked):

- **actionlint 1.7.12**: `actionlint_1.7.12_linux_amd64.tar.gz`, fetched with `gh release download`.
  - The sha256 was verified against `actionlint_1.7.12_checksums.txt` and matched (OK).
  - Note that the checksums file comes from the same release, so this checks integrity, not provenance.
  - The default run used the host `/usr/bin/shellcheck` 0.9.0. A rerun with `-shellcheck vendor-bin/shellcheck` (v0.11.0) gives the same 14 findings.
  - pyflakes is not installed, so the pyflakes kind did not run.
- **zizmor 1.31.1**: installed into the venv `untracked/tools/zizmor-venv`.
  - It ran **offline only**. There is no `GH_TOKEN`/`GITHUB_TOKEN` in the environment, `gh` stores its token in `hosts.yml`, and `gh auth token` is forbidden.
  - The online-only audits (impostor-commit, known-vulnerable-actions, ref-confusion, stale-action-refs) therefore did not run.

Raw outputs are in `untracked/scratch/al-wf.json`, `al-tpl.json`, `zz-off.json`, `zz-tpl.json`, `zz-pedantic.json` and `zz-auditor.json`.

Note that `templates/github-actions/php-qa-ci.yml` is byte-identical to `.github/workflows/qa.yml`, and `templates/github-actions/approve-held-ci/action.yml` is identical to `.github/actions/approve-held-ci/action.yml`. Every finding below therefore appears twice, once per copy.

Note that zizmor, given a directory that is not `.github/`, only picks up `action.yml`. The template `.yml` files have to be passed explicitly.

### actionlint: 14 findings (8 in `.github/workflows`, 6 in templates)

| Kind         | File                                         | Count | Notes                                                                                                                                                                                                                      |
| ------------ | -------------------------------------------- | ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| syntax-check | qa.yml:87 / php-qa-ci.yml:87                 | 1+1   | "string should not be empty": the `workflow_dispatch` choice input `options: - ''`. This is a real schema violation; the empty option is meant to mean "full pipeline"                                                     |
| shellcheck   | qa.yml / php-qa-ci.yml (lines 129, 193, 233) | 4+4   | All SC2086 (info). Line 233 is the `"$QA" -t ${{ github.event.inputs.qa_tools }}` run block                                                                                                                                |
| shellcheck   | update-deps.yml:43, 149                      | 3     | SC2086 info, plus SC2129 style ×2                                                                                                                                                                                          |
| expression   | templates qa-autofix.yml:242                 | 1     | `"github.head_ref" is potentially untrusted` used directly in an inline script (`git push origin "HEAD:${{ github.head_ref }}"`, line 254). **Notable**: this is real injection-class code in a template consumers install |

ci.yml, release.yml (both copies) and the composite action are clean under actionlint. actionlint does not lint a composite `action.yml` standalone.

### zizmor offline, default "regular" persona: 40 (.github) + 36 (templates)

| Audit (severity/confidence)     | .github/workflows                          | templates                                  |
| ------------------------------- | ------------------------------------------ | ------------------------------------------ |
| unpinned-uses (High/High)       | ci 2, qa 11, release 3, update-deps 5 = 21 | php-qa-ci 11, qa-autofix 4, release 3 = 18 |
| template-injection (High/High)  | qa 5                                       | php-qa-ci 5, qa-autofix 1                  |
| dangerous-triggers (High/Med)   | release 1                                  | release 1                                  |
| excessive-permissions (Med/Med) | ci 1, qa 4                                 | php-qa-ci 4                                |
| artipacked (Low/Low)            | ci 1, qa 3, release 1, update-deps 1       | php-qa-ci 3, qa-autofix 2, release 1       |
| self-repository (Low/High)      | release 1, update-deps 1                   | release 1                                  |

The exit code was 14 (highest severity High).

Under the pedantic persona there are 94 findings (adds concurrency-limits 4, superfluous-actions 3, undocumented-permissions 4). Under the auditor persona there are 96 (adds secrets-outside-env 2).

Notable findings:

- **template-injection**:
  - qa.yml/php-qa-ci.yml:237–239 interpolate `${{ github.event.inputs.qa_tools }}` into `run:` (including unquoted, line 239).
  - Lines 281 and 302 interpolate `${{ github.event.repository.default_branch }}` / `${{ github.workflow }}`.
  - qa-autofix.yml:254 interpolates `${{ github.head_ref }}`. This is the one a PR author controls; actionlint flags it too.
  - qa_tools is a `choice` input, so the practical risk is low, but the pattern is the injection class.
- **unpinned-uses**: every third-party action is pinned to a tag, not a SHA. Examples: `shivammathur/setup-php@v2`, `irongut/CodeCoverageSummary@v1.3.0`, `marocchino/sticky-pull-request-comment@v3`, `peter-evans/create-pull-request@v8`, `actions/*@vN`. The templates ship this to consumers.
- **dangerous-triggers**: release.yml uses `on: workflow_run`. This is by design (release gating), and the file sets `permissions: {}` at top level. It is a candidate for a justified suppression, not a fix.
- **excessive-permissions**: qa.yml (and the template) has no `permissions:` block, so default token permissions apply (line 56, plus three jobs).
- **artipacked**: `actions/checkout` runs without `persist-credentials: false`.

There is prior context: CLAUDE/Plan/Completed/00004-qa-ecosystem-lanes/PLAN.md:35 listed actionlint as an explicit non-goal ("Non-PHP toolchains").

---

## 3. actions-qa-ci sketch in DBF terms

### Toolchain and pinning

- **actionlint**: Go static binary. Pin the version in `vendor-bin/actionlint.version` plus a recorded sha256 per platform, and verify against the release `checksums.txt` and a committed hash (trust on first use, then pinned). This mirrors how shellcheck is installed: `src/ShellCheck/ShellCheckInstaller.php` + `InstallDecider`, `bin/shellcheck-install`, a version file, and a crash on mismatch. Optionally verify the release's GitHub artifact attestation **[unverified whether actionlint publishes attestations]**. Point actionlint's `-shellcheck` at the same pinned shellcheck php-qa-ci already vendors; the rerun showed this works.
- **zizmor**: available as PyPI wheels or GitHub release binaries **[unverified: release binary assets not checked]**.
  - Preferred: pin a release binary plus sha256, so there is no Python runtime dependency.
  - Otherwise: `pip install --require-hashes -r requirements.lock` into a project-local venv.
  - Run `--offline` by default. Online audits (impostor-commit, known-vulnerable-actions, ref-confusion) only in CI, where `GITHUB_TOKEN` exists.
- **Optional lanes**: poutine (Apache-2.0 binary, SARIF, Rego custom rules), and pinact or ratchet as the *fixer* for unpinned-uses (DBF "fixers fix" in writable mode). CodeQL `actions` belongs in a separate code-scanning workflow, not the local gate. Scorecard is a scheduled posture job. Avoid redistributing Semgrep rules (licence) and secure-repo (AGPL).
- **Dedupe**: zizmor and actionlint overlap on injection (`expression` "potentially untrusted" vs `template-injection`), and so do poutine and octoscan. Pick one owner per hazard class so a hazard has one identifier.

### Stable identifiers

The namespace is `actionsqaci.<tool>.<upstream-id>`. Examples:

- `actionsqaci.zizmor.unpinned-uses`, `actionsqaci.zizmor.template-injection`
- `actionsqaci.actionlint.syntax-check`, `actionsqaci.actionlint.expression`
- `actionsqaci.actionlint.shellcheck.SC2086`: shellcheck codes come through the actionlint message, so parse `SCxxxx` out of it
- `actionsqaci.poutine.untrusted_checkout_exec`

The upstream ids are already stable and documented (zizmor anchors, actionlint kinds, poutine slugs, ghalint numbers), so each page can be a thin local page: the correct construction plus a link to the upstream anchor. The exception is the actionlint kinds: they are coarse ("expression" covers type errors *and* untrusted input). A finer id needs a message-pattern map maintained by actions-qa-ci, which is fragile across actionlint releases; add a version-pin test.

Also needed:

- First-party lanes that stand alone, such as `actionsqaci.versionPins` (setup-php / PHP version), `actionsqaci.templateDrift` (template vs deployed copy), and `actionsqaci.lintCoverage` (every workflow/action file reached). These close the gap of zizmor silently skipping files outside `.github/`.
- A `rules` listing and a `rule-doc <id>` (offline), reusing the identifier-index format of `docs/phpstan-rules/README.md` and `RuleDocResolver` (`src/PHPStan/RuleDocResolver.php`, which already resolves project-declared indexes from `qaConfig/rule-docs.json`).

### Justification lane (suppressions)

- **zizmor**: allow suppressions only in `zizmor.yml` `rules.<id>.ignore` entries (file:line:col), each with a preceding YAML comment. The lane rejects generic reasons using the same logic as `IgnoreErrorsJustificationDetector`.
  - Inline `# zizmor: ignore[...]` comments are either banned (the lane greps for them and fails) or required to carry a non-generic trailing reason.
  - Run CI with `--no-ignores` in a secondary report so the accepted set stays visible.
- **actionlint**: has no inline suppression and no reason field. Allow only `.github/actionlint.yaml` `paths: ... ignore: [regex]` with a comment above each regex. Forbid `-ignore` on the command line, which the single entry point enforces ("not a tool proxy").
- **ratchet** `# ratchet:exclude` and **pinact** `ignore_actions`: the same comment-justification rule.
- Example: release.yml `dangerous-triggers` (workflow_run) is the first legitimate justified entry.

### Single entry point and outputs

- One entry point: `actions-qa [-t lane] [-p path]`, the same lane model as `bin/qa`.
- Each lane normalises to one finding DTO (id, file, line, severity, upstream-url).
- Output is a merged SARIF (zizmor and poutine emit SARIF natively; actionlint via its SARIF template), uploaded with `github/codeql-action/upload-sarif`. Set a `category` per tool so dismissals stay stable.
- A generated "Active defences" agent summary, like `rules --write-agent-summary`.

### Reuse from php-qa-ci

**Reusable as-is or near-generic:**

- The identifier-index and rule-doc mechanics: `RuleDocResolver`, the `bin/rule-doc` and `bin/rules` shapes, `AgentContext/ActiveDefencesSummary` + `AgentContextRegion`.
- The justification lane: `src/PHPStan/ProjectRecord/IgnoreErrorsJustification*`. Its generic-reason detector is the reusable part; the neon parsing is PHP-specific.
- The pinned-binary fetch/verify: `src/ShellCheck/*`, `bin/shellcheck-install`, `vendor-bin/*.version`. This generalises to a "PinnedBinary" installer for actionlint, zizmor and poutine.
- Changelog-driven release: `src/Changelog/*`, `changelog-release`, the release.yml template.
- The branch policy lane, the `docsProse`/`markdownLinks`/`markdownFormat` docs lanes, the Claude skills/agents deployment (`ComposerPlugin/SkillsDeployPlugin`), and the issue-monitor loop.

**PHP-specific, not reused:** PHPStan rules and extensions, composer plugins (PhpStanGuard, ManagedSource, Turbo), psr4/composer checks, rector, php-cs-fixer, infection, phpunit, the opcache/phplint lanes, Arkitect.

### Language choice

**PHP entry point.** This maximises reuse (all of the above is PHP; the Owner's "PHP for logic" rule).

- Pros: one codebase, and php-qa-ci can embed it as a lane trivially.
- Cons: every non-PHP repo needs a PHP 8.5 runtime just to orchestrate Go/Rust binaries, which hurts "usable in any repo". This could be mitigated by a PHAR plus a GitHub composite action that installs PHP, or a container image.

**Polyglot runtime** (Go single binary, or a Python package since zizmor already ships on PyPI):

- Pros: zero-dependency install in any repo, and a natural fit with Go-based actionlint/poutine (Go can even embed actionlint as a library).
- Cons: it re-implements the DBF mechanics (index, rule-doc, justification, changelog release) in a second language and forks the conventions; the Owner rule only governs php-qa-ci.

**Middle path.** Keep DBF data language-neutral: a JSON/YAML identifier index, Markdown pages, and a SARIF-normalised finding schema. Ship actions-qa-ci as a PHAR plus an official container/action wrapper. php-qa-ci then consumes it as a lane (`phpqaci.actionsQa`), and a later port to Go only rewrites the runner, not the data.

The decision is the Owner's. It hinges on whether "any repo" means repos without PHP.
