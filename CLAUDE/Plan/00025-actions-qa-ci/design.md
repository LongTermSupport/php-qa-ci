# actions-qa-ci: design (Plan 00025, Task 4.1)

This document holds the decisions for `LongTermSupport/actions-qa-ci`. It is a standalone, polyglot
Defence Before Fix (DBF) toolchain for GitHub Actions code: workflows, composite actions and
workflow templates in any repository. php-qa-ci is its first consumer. Inputs:
[PLAN.md](PLAN.md), [research/ci-audit.md](research/ci-audit.md),
[research/actions-tool-survey.md](research/actions-tool-survey.md), the method
(<https://defence-before-fix.github.io/>), and its toolchain and detector specifications (vendored
in php-qa-ci under `remote-docs/defence-before-fix.github.io/`, toolchain spec 0.2.0, detector spec
1.0.0). Clause references such as "toolchain 4.3" and "detector 6.5" refer to those two documents.

The binding constraint is Owner decision 1.2: actions-qa-ci is not PHP-specific, and it must be
easy to install in any Linux environment **without a host language runtime**.

Upstream facts were checked on 2026-10-21 with `gh api repos/<r>/releases/latest`, by downloading the
assets and inspecting their linkage, and from the URLs cited. Items that need an Owner decision
are marked **[Owner]** and collected in [section 14](#14-owner-decisions-needed).

---

## 1. Decisions at a glance

| #   | Decision                                                                                                                                                                    |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | The entry point is one **statically linked Go binary**, `actions-qa` (`CGO_ENABLED=0`). actionlint is compiled in as a Go library.                                          |
| D2  | It is distributed as a **per-arch release bundle** (`linux_amd64`, `linux_arm64`) holding `actions-qa`, zizmor, shellcheck and pinact, all static.                          |
| D3  | It installs through a versioned **POSIX-sh `install.sh`** that carries the bundle's sha256, through a **composite action** for CI, and (later) an OCI image.                |
| D4  | Identifiers are `actionsqaci.<tool>.<upstream-id>` for wrapped upstream checks and `actionsqaci.<camelName>` for first-party checks. Each hazard class has one owner (§4).  |
| D5  | Every identifier has a page embedded in the binary, which `actions-qa rule-doc` resolves offline. The index is generated from the registry, and the release fails on a gap. |
| D6  | **One project record**, `.github/actions-qa-ci.yml`. Exceptions are applied by actions-qa-ci after the tools have run. Every native ignore route is disabled or forbidden.  |
| D7  | Bespoke project rules are **CEL predicates over a selector** of the parsed workflow model, so actions-qa-ci itself is a Detector that hosts project rules (detector 4.1).   |
| D8  | The output is one merged **SARIF** run (driver `actions-qa-ci`, upstream tools as `extensions`), plus `text`, `agent`, `json` and `github` formats.                         |
| D9  | Tool currency copies php-qa-ci's process: a daily `update-tools` workflow, a PR when green, a failure issue when red, and a changelog-driven release.                       |
| D10 | php-qa-ci consumes it as the lane `phpqaci.actionsQa`, which runs a pinned `actions-qa`. The lane passes the identifiers through unaltered and routes their `rule-doc`.     |

---

## 2. Toolchain language and distribution

### 2.1 Upstream tools as they ship

| Tool       | Version, licence                                                      | Linux assets                                                                                                                                                                     | Linkage (checked with `ldd` / ELF headers)                                                                                                                   | Integrity available upstream                                                                                                                     |
| ---------- | --------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| actionlint | v1.7.12, MIT ([repo](https://github.com/rhysd/actionlint))            | `linux_amd64`, `linux_arm64`, `386`, `armv6` tarballs                                                                                                                            | `not a dynamic executable` (static Go)                                                                                                                       | `actionlint_1.7.12_checksums.txt`, and a GitHub artifact attestation exists for the amd64 tarball (`gh api …/attestations/sha256:<digest>`: 1)   |
| zizmor     | v1.31.1, MIT ([repo](https://github.com/zizmorcore/zizmor))           | Release: only `x86_64-unknown-linux-gnu` and `aarch64-unknown-linux-gnu`. PyPI: `musllinux_1_2_{x86_64,aarch64,armv7l}` wheels ([PyPI](https://pypi.org/project/zizmor/1.31.1/)) | The **gnu release binary is dynamic** (libc, libm, libgcc_s), so it fails on musl. The binary inside the **musllinux wheel is static** on x86_64 and aarch64 | Attestation on the release tarballs (2). The PyPI digest is sha256; the PyPI provenance endpoint returns "No provenance available" for the wheel |
| pinact     | v5.0.0, MIT ([repo](https://github.com/suzuki-shunsuke/pinact))       | `linux_amd64`, `linux_arm64`                                                                                                                                                     | static Go                                                                                                                                                    | `checksums.txt`, a sigstore bundle for it, `multiple.intoto.jsonl`, SBOMs and an attestation (2)                                                 |
| shellcheck | v0.11.0, **GPL-3.0** ([repo](https://github.com/koalaman/shellcheck)) | `linux.{x86_64,aarch64,armv6hf,riscv64}`                                                                                                                                         | static (php-qa-ci's `vendor-bin/shellcheck`: `not a dynamic executable`)                                                                                     | No checksums file, so the hash is pinned on our side                                                                                             |

zizmor is the one awkward tool. A wheel is a zip file, so the installer takes the static binary out
of `zizmor-<v>.data/scripts/zizmor` without Python. That one asset runs on both glibc and musl.

### 2.2 Options compared

| Option                                               | Host requirement                                        | Hosts project rules (detector 4.1)               | Verdict                                                                                                                                                                                                           |
| ---------------------------------------------------- | ------------------------------------------------------- | ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Go static binary + bundled static tools** (chosen) | Linux kernel; `sh`, `tar`, `sha256sum` only for install | Yes: CEL rules over actionlint's AST, in-process | Chosen. Cross-compiling without CGO is trivial. actionlint is a Go library with an `OnRulesCreated` hook (`linter.go` at v1.7.12), and we get its parsed, positioned AST for free. musl and glibc are irrelevant. |
| POSIX-sh installer of pinned binaries + a sh runner  | sh, jq/yq                                               | No sane route                                    | Rejected. Merging SARIF, applying exceptions and parsing YAML positions in sh needs jq/yq on the host. It also breaks the "Bash is for invocation" convention. sh is kept **only** as the installer.              |
| Container image as the primary channel               | Docker/Podman and a registry pull                       | Yes                                              | Rejected as primary. Many agent sandboxes and nested CI have no container runtime, and it adds latency to a pre-push hook. Kept as a secondary channel for non-GitHub CI (Phase 7).                               |
| Composite GitHub Action only                         | GitHub runner                                           | n/a                                              | Rejected as the *only* channel, because it cannot run locally and so fails detector 5.1 and 5.4. Kept as a thin CI wrapper around the bundle.                                                                     |
| Rust (could link zizmor's crates)                    | none                                                    | Yes                                              | Rejected. zizmor publishes no stable library API, actionlint is Go, and we would have to rebuild its AST. Rust would buy zizmor in-process at the cost of actionlint in-process.                                  |
| Python package (zizmor already on PyPI)              | Python                                                  | Yes                                              | Rejected: a host runtime (decision 1.2).                                                                                                                                                                          |
| PHP PHAR (the survey's "middle path")                | PHP 8.5                                                 | Yes                                              | Rejected: a host runtime (decision 1.2).                                                                                                                                                                          |

### 2.3 Bundle, install and verification

- **Release assets** for each tag `vX.Y.Z`:
  - `actions-qa-ci_X.Y.Z_linux_{amd64,arm64}.tar.gz`, containing:
    - `bin/actions-qa`, `bin/zizmor`, `bin/shellcheck`, `bin/pinact`;
    - `LICENSES/` (MIT ×3, GPL-3.0, with a pointer to the shellcheck source tag that satisfies the
      GPL's corresponding-source offer; the bundle is mere aggregation);
    - `SBOM.spdx.json`.
  - `checksums.txt`.
  - A GitHub artifact attestation per tarball (`actions/attest-build-provenance`).
  - `install.sh`.
- **`install.sh` is versioned per release.** The release workflow writes the two bundle sha256s
  into the script. Running it:
  1. It detects the arch with `uname -m`.
  2. It downloads with `curl` or `wget`. With `ACTIONS_QA_CI_BUNDLE=/path/to/tarball` it uses a local
     file instead (air-gapped).
  3. It verifies with `sha256sum`, `shasum -a 256` or `openssl dgst`, whichever exists. A mismatch
     exits non-zero and installs nothing.
  4. It extracts to `${ACTIONS_QA_CI_HOME:-${XDG_DATA_HOME:-$HOME/.local/share}/actions-qa-ci}/X.Y.Z/`,
     or to `--prefix`.
- **Air-gapped install:** copy one tarball and `install.sh` across, and the same verification
  applies. The checks need no network. The online zizmor audits are skipped *by name* (§7.4).
- **Runtime self-verification:** `actions-qa` embeds the manifest (§5). Before each run it checks
  the sha256 of each sibling tool against the manifest. That catches a hand-swapped or mixed-version
  tool, and costs one read of each file. A mismatch exits 3.
- **Stronger provenance** is optional and documented: `gh attestation verify <tarball> -R LongTermSupport/actions-qa-ci`.
  It is not required for install, because `gh` is not a given on the host.
- **Platforms in v1:** Linux amd64 and arm64. **[Owner]** whether darwin and armv7 are in scope.
  The tools exist for darwin, but the Owner asked for Linux.
- **pyflakes is not run.** It needs Python. `run:` scripts with `shell: python` are therefore not
  linted for Python errors. This is recorded as a `known-gaps` entry (§12), not hidden.

---

## 3. Conformance framing (toolchain 4.1)

Toolchain 4.1 says that a Detector which "cannot host a bespoke Rule at all cannot be wrapped into
Conformance, and no Defence is routed through it". zizmor and actionlint (as a CLI) host no project
rules: zizmor offers configuration only, and actionlint offers only Go-level `OnRulesCreated`.

The design therefore makes **actions-qa-ci the Detector**:

- it parses every workflow and action itself (actionlint's AST);
- it hosts project rules (D7);
- it has a harness (`actions-qa rule <id> <path>`);
- it prints stable identifiers, runs locally down to one file, and resolves every identifier
  offline;
- it controls every suppression route (§8).

zizmor and actionlint's built-in checks are **bundled rule backends**. Their findings are re-keyed
into our identifier namespace, documented by us, and Blocking through our entry point. That makes
them our Bundled defences, so self-audit (toolchain 8.1) covers them in full.

**[Owner]** Confirm this reading. It is a reading of 4.1 that the specification does not spell out,
so it is worth putting to the specification's author. The fallback reading treats zizmor and
actionlint findings as Blocking "checks" that are not Defences. It changes no code, only the
conformance declaration.

---

## 4. Identifiers and one owner per hazard class

### 4.1 Scheme

| Kind                          | Form                                                               | Examples                                                                    |
| ----------------------------- | ------------------------------------------------------------------ | --------------------------------------------------------------------------- |
| zizmor audit                  | `actionsqaci.zizmor.<audit-id>` (upstream kebab-case, unaltered)   | `actionsqaci.zizmor.template-injection`, `actionsqaci.zizmor.unpinned-uses` |
| actionlint kind               | `actionsqaci.actionlint.<kind>`                                    | `actionsqaci.actionlint.syntax-check`, `actionsqaci.actionlint.expression`  |
| shellcheck through actionlint | `actionsqaci.shellcheck.SC<nnnn>` (code parsed from the message)   | `actionsqaci.shellcheck.SC2086`                                             |
| first-party check             | `actionsqaci.<camelName>` (two segments, the php-qa-ci convention) | `actionsqaci.templateDrift`, `actionsqaci.unjustifiedException`             |
| project rule                  | the project's own prefix; the loader rejects `actionsqaci.`        | `phpqaci.workflowNoSelfHostedOnFork`                                        |

These rules hold:

- Identifiers are Go constants in one registry. A test fails on any finding constructed with a
  literal identifier (the actions-qa-ci counterpart of detector 4.4).
- An identifier, once released, keeps resolving for as long as any released version can print it.
- When upstream removes a check, its page stays and is marked "retired in tool vX".

### 4.2 Hazard owners where the tools overlap

A hazard reported twice gets two identifiers and two exceptions, so each hazard has one owner. The
other tool's report of it is dropped in the backend adapter. A fixture test pins every drop, so an
upstream message change fails the build instead of re-admitting a duplicate.

| Hazard class                                                                             | Owner                                                                        | Dropped duplicate                                                                                                                                                      | Why this owner                                                                                                         |
| ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Untrusted input interpolated into `run:` / scripts                                       | `zizmor.template-injection`                                                  | actionlint `expression` messages of the "potentially untrusted" family. They are matched by message pattern, because `expression` also carries type errors, which stay | zizmor understands more contexts and has a remediation page and an auto-fix ([audits](https://docs.zizmor.sh/audits/)) |
| Hard-coded container credentials                                                         | `zizmor.hardcoded-container-credentials`                                     | actionlint `credentials`                                                                                                                                               | Same hazard; zizmor's page states the fix                                                                              |
| Mutable action / reusable-workflow refs                                                  | `zizmor.unpinned-uses`                                                       | pinact `run --check` is used as the **fixer**, not as a reporter                                                                                                       | One identifier; pinact rewrites refs in `fix` mode                                                                     |
| Pin comment that does not match the pinned SHA                                           | `zizmor.ref-version-mismatch`                                                | pinact `--verify-comment` (its code 001) is not run as a reporter                                                                                                      | zizmor already ships it as an audit                                                                                    |
| Least-privilege token                                                                    | `zizmor.excessive-permissions` (and `undocumented-permissions` when enabled) | none. actionlint `permissions` is a **different class**: an invalid scope or value is a schema error                                                                   | Kept separate                                                                                                          |
| Workflow schema, expression types, `needs`, matrix, runner labels, globs, `uses:` inputs | `actionlint.*`                                                               | n/a                                                                                                                                                                    | zizmor does not check these                                                                                            |
| Shell defects in `run:`                                                                  | `shellcheck.SC*`                                                             | n/a                                                                                                                                                                    |                                                                                                                        |
| Deprecated workflow commands (`set-output`)                                              | `actionlint.deprecated-commands`                                             | n/a. zizmor `insecure-commands` (`ACTIONS_ALLOW_UNSECURE_COMMANDS`) is a different class                                                                               |                                                                                                                        |

The ownership table is data in the registry. `actions-qa rules` prints the dropped duplicates under
their owner, so the reason a hazard has only one identifier is visible.

---

## 5. Pinned-tool manifest and the update path

### 5.1 Manifest

`toolchain/manifest.json` sits in the actions-qa-ci repository and is embedded in the binary with
`go:embed`, so a binary version always names exactly one tool set:

```json
{
  "schema": 1,
  "tools": {
    "zizmor": {
      "version": "1.31.1",
      "licence": "MIT",
      "source": "https://github.com/zizmorcore/zizmor/releases/tag/v1.31.1",
      "assets": {
        "linux_amd64": {
          "url": "https://files.pythonhosted.org/…/zizmor-1.31.1-py3-none-musllinux_1_2_x86_64.whl",
          "sha256": "e7fdf82d31cc67eb1d5030bf2372b25ee2a1c3ad05017e18da70ed138c4accd6",
          "member": "zizmor-1.31.1.data/scripts/zizmor",
          "binarySha256": "…"
        }
      },
      "provenance": "pypi-digest"
    }
  }
}
```

- `sha256` pins the downloaded archive and `binarySha256` pins the extracted executable. The second
  is what the runtime self-check compares.
- `provenance` records which upstream check the update job passed: `attestation`,
  `sigstore-checksums`, `checksums-file` or `pypi-digest`.
- actionlint is **not** in the manifest. It is pinned in `go.mod`/`go.sum`, which Go's checksum
  database verifies. actionlint's library "does not follow semantic versioning"
  ([api.md](https://github.com/rhysd/actionlint/blob/main/docs/api.md)), so the fixture corpus is
  the guard on each bump.
- A test asserts that `manifest.json`, `go.mod` and the generated `docs/rules/README.md` agree on
  versions.

### 5.2 zizmor provenance

The static musl wheel has no upstream provenance. The attested release tarball is glibc-only and
dynamically linked. Options:

- **(a, chosen for v1)** Pin the musl wheel by sha256, and have the update job cross-check the
  wheel's digest against PyPI's JSON API.
- **(b)** Build zizmor from its tagged source for `*-unknown-linux-musl` in our own release
  workflow, and attest the result ourselves.

**[Owner]** whether (b) is required before v1.0.

### 5.3 Update path (php-qa-ci's tool-currency process, copied)

The `update-tools.yml` workflow runs daily and on `workflow_dispatch`:

1. It runs `actions-qa-dev tools bump`, a dev-only Go command in `internal/`. For each tool it
   queries the latest release (GitHub API, or PyPI JSON for zizmor), downloads the asset, verifies
   it by the strongest route upstream offers (`gh attestation verify`, cosign/sigstore, the
   checksums file, the PyPI digest), and rewrites the manifest. Then it runs
   `go get github.com/rhysd/actionlint@latest`.

2. It regenerates the upstream identifier sets:

   - the zizmor audit ids, from `crates/zizmor/src/audit/` at the new tag (41 audits at v1.31.1);
   - the actionlint kinds, through its `allKinds`;
   - the shellcheck codes, from the pinned shellcheck source.

   A **new upstream id with no page fails the self-audit** (§6.3). The update PR therefore stays
   red until the page is written. This is the DBF ratchet applied to the toolchain.

3. It runs the full self-check, the fixture corpus, and a dogfood run over a pinned php-qa-ci
   checkout.

4. It adds the tool moves under `## Unreleased`.

5. When green, it opens or updates `chore/update-tools`. When red, it comments on (or opens) the
   `update-tools-failure` issue.

The merge policy follows php-qa-ci: a green update PR is merged after a fresh sub-agent verifies
it. **[Owner]** whether that standing authorisation extends to the new repository.

**[Owner]** A minimum release age before adoption. pinact supports a cooldown
([update.md](https://github.com/suzuki-shunsuke/pinact/blob/main/docs/update.md)), and zizmor
audits the same idea in Dependabot (`dependabot-cooldown`). The proposal is 3 days. php-qa-ci
takes the latest version the same day, so this would be a deliberate difference.

The actions that actions-qa-ci's own workflows use are SHA-pinned. `actions-qa fix` (pinact)
updates them, and the zizmor `unpinned-uses` audit enforces it, so the toolchain checks itself.

---

## 6. `rules`, `rule-doc`, the index and the pages

### 6.1 Commands (toolchain 5.1–5.3, 6.3, 7.1)

| Command                                         | Does                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `actions-qa rules [--json]`                     | Lists every **active** defence in the project **without running it**. The list is derived from the registry plus the loaded project record: bundled, first-party and the project's own CEL rules. Each entry gives the identifier, a terse standing instruction and the route to its page. A second section lists the project record (exceptions, narrowings, opt-ins, unanalysed paths) **by the same operation** (toolchain 6.3). |
| `actions-qa rules --write-agent-summary=<file>` | Writes the same list into a delimited generated region (`<!-- actions-qa-ci:begin -->…`) of `CLAUDE.md` / `AGENTS.md`. `actions-qa install` and the composite action refresh it, and the lane `actionsqaci.agentSummaryCurrent` fails when it is stale (toolchain 7.1, 7.2).                                                                                                                                                        |
| `actions-qa rule-doc <identifier>`              | Prints the page from the copy embedded in the binary, offline. It also resolves the project's own identifiers from `.github/actions-qa-ci/docs/<id>.md` (toolchain 4.2). An unknown identifier exits 2 and suggests the nearest identifiers.                                                                                                                                                                                        |
| `actions-qa rule <identifier> <path>`           | The **harness** (detector 4.2): runs one rule on one file and says whether it fired and what it printed, with no other rule's output.                                                                                                                                                                                                                                                                                               |

### 6.2 Pages

- Pages live in `docs/rules/<identifier>.md`, one page per identifier. The one family page is
  shellcheck (§6.4).
- Every page has the same sections:
  - **What it forbids or requires**;
  - **Hazard** (one sentence);
  - **Correct construction**: the project's preferred construction as a YAML snippet, not just the
    prohibition (method 3.6);
  - **Exceptions**: how to record one and what a valid justification names;
  - **Upstream**: the tool, the version, and a link to the upstream anchor (for example
    `https://docs.zizmor.sh/audits/#template-injection` or
    `https://github.com/rhysd/actionlint/blob/v1.7.12/docs/checks.md#…`).
- For zizmor pages, the upstream remediation text may be vendored under its MIT licence, with
  provenance (tool, tag, path). The correct-construction section is always ours. Vendoring
  explains the hazard; it does not state our preferred fix.

### 6.3 Index and self-audit (toolchain 8.1, detector 6.3–6.5)

- `docs/rules/README.md` is the identifier index. It is **generated** from the registry and is not
  edited by hand. A test fails when it is stale.
- The self-audit test enumerates every identifier the pinned tools and first-party code can print,
  using the sets from §5.3 step 2, and asserts that each resolves to an existing page through
  `rule-doc`. The release workflow runs it, so a missing page blocks the release.

### 6.4 ShellCheck codes

There are a few hundred SC codes. One family page states the general correct construction (quote
expansions, `set -euo pipefail`, pass inputs through `env:`), and its member regex
`^actionsqaci\.shellcheck\.SC[0-9]{4}$` lets a check confirm each member (detector 6.5).

A family page cannot state the correct construction for each code. Bootstrap therefore decides
between two routes:

- vendor the ShellCheck wiki pages, with provenance, if their licence allows it;
- write our own page for each code at severity ≥ warning, with the self-audit enumerating the codes
  from the pinned shellcheck source.

The default severity is `warning`. That matches php-qa-ci's shellCheck lane (`ShellCheck (severity=warning)`)
and keeps SC2086-style `info` codes out until the Owner decides otherwise. Lowering the threshold
is an opt-in in the project record.

---

## 7. Entry point

### 7.1 CLI

```text
actions-qa [check] [-t <lane>[,<lane>…]] [--format text|agent|json|github] [--sarif <file>] [<path>…]
actions-qa fix     [-t <lane>…] [<path>…]     # pinact pin/update + zizmor --fix=safe; writable by default
actions-qa rules | rule-doc <id> | rule <id> <path> | install | doctor | version [--json]
```

- **Not a tool proxy** (convention from php-qa-ci). There are no pass-through flags: no
  `--persona`, no `-ignore`, no `--min-severity`, no `--config`. Each tool's configuration is
  generated from the project record. Anything a consumer wants different is a record entry with a
  justification.
- **Lanes** are `actionlint`, `shellcheck`, `zizmor`, `firstParty` and `projectRules`, each runnable
  alone with `-t`. A path argument narrows any lane down to one file (detector 5.2). The default
  run is every lane over every analysed file (§10.2).
- **Order** (toolchain 4.5): the record is validated first, and an invalid record exits 2 before any
  analysis. Then the lanes run, and they are all Detectors. actions-qa-ci has no Runners, so the
  order only matters to a host pipeline. In php-qa-ci the lane sits in the static phase, ahead of
  phpunit.
- **Writable by default:** `fix` writes. `ACTIONS_QA_READONLY=1` (set automatically when
  `GITHUB_ACTIONS=true`) makes `fix` report what it would change and exit 1. This copies php-qa-ci's
  `QA_READONLY`.

### 7.2 Exit codes

| Code | Meaning                                                                                                                                                                                                                              |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 0    | Ran, no unexcepted finding                                                                                                                                                                                                           |
| 1    | Ran, findings                                                                                                                                                                                                                        |
| 2    | Usage or record error: an unknown lane or identifier, an invalid record, an unjustified exception. Nothing was analysed                                                                                                              |
| 3    | Toolchain fault: a tool missing or failing its hash, a tool exiting with its own error code (zizmor 1/2/3, actionlint 2/3)                                                                                                           |
| 4    | **No verdict**: a tool was killed by a signal or exited 139. The run halts and reports which tool. It never retries and never reports success. This copies php-qa-ci's segfault policy and the #275 "lane signaled → no verdict" fix |

zizmor's 11–14 severity codes and actionlint's exit 1 are mapped into 0/1 after exceptions are
applied. actions-qa-ci reads their JSON and decides from the findings themselves, not from their
exit codes.

### 7.3 Output

- **`text`** (TTY default): grouped by file, with the identifier printed unaltered on every
  finding (toolchain 4.4), and `rule-doc` named once per identifier.

- **`agent`** (the default when not a TTY, or with `ACTIONS_QA_FORMAT=agent`): one line per finding,
  `path:line:col identifier message`, then one `rule-doc <id>` line per distinct identifier, then a
  one-line count. No colour and no banners. Full detail goes to the `--sarif` file, and that path is
  printed (detector 5.3).

- **`json`**: the finding schema `{id, tool, upstreamId, path, line, col, endLine, severity, message, docPath, excepted, exceptionKey}`.

- **`github`**: workflow annotations, capped at GitHub's per-step limit, with the full list in the
  log.

- **SARIF merge:** one SARIF 2.1.0 log with **one run**:

  - `tool.driver` is `actions-qa-ci`; zizmor, actionlint and shellcheck are `tool.extensions`
    with their versions;
  - `ruleId` is our identifier, with the upstream id in `properties`;
  - `helpUri` is the page at the release tag;
  - an excepted finding carries `suppressions: [{kind: "external", justification: …}]`, so code
    scanning shows it as suppressed and does not drop it.

  A single run is chosen because GitHub rejects an upload with several runs of the same
  tool and category
  ([changelog](https://github.blog/changelog/2025-07-22-code-scanning-will-stop-combining-multiple-sarif-runs-uploaded-in-the-same-sarif-file)).
  Rejected: one run per tool with a category each. That works, but it splits one defence set
  across three code-scanning tools and makes consumers manage categories.

### 7.4 Online audits

zizmor's online audits (`impostor-commit`, `known-vulnerable-actions`, `ref-confusion`,
`stale-action-refs`, …) run when `GH_TOKEN` or `GITHUB_TOKEN` is set. Otherwise they are
**skipped by name**: the output lists each skipped identifier and the reason (`offline`).

This matches the Owner's #292 ruling ("default-on, skipping by name when offline"). It also
satisfies detector 5.4, because a practitioner with a token runs them locally.

---

## 8. Suppression model and the justification lane

### 8.1 Decision: one project record; native routes closed

Each tool's native route was assessed and closed:

| Native route                                                                                                                        | Disposition                                                                                                                                                                                                                                                                                                                                                                                           |
| ----------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| zizmor inline `# zizmor: ignore[id] reason` (reason optional)                                                                       | **Inert and forbidden.** zizmor runs with `--no-ignores` and a generated `--config`, and the first-party check `actionsqaci.inlineSuppression` fails on every such comment. It is inert because actions-qa-ci, not zizmor, applies exceptions. It is still forbidden because a comment that looks like a suppression but does nothing misleads the reader, and running bare `zizmor` would honour it. |
| zizmor `zizmor.yml` / `.github/zizmor.yml` `rules.<id>.ignore` / `disable` ([configuration](https://docs.zizmor.sh/configuration/)) | **Forbidden.** `actionsqaci.nativeSuppressionConfig` fails when such a file exists. Discovery would otherwise pick it up whenever zizmor is run directly. zizmor's per-audit `config:` settings, such as the `unpinned-uses` policy and `forbidden-uses`, are expressed in our record instead.                                                                                                        |
| actionlint `-ignore` / `.github/actionlint.yaml` `paths.*.ignore` (regex, no reason)                                                | **Forbidden.** We never pass `-ignore`, and the same check fails on an `actionlint.yaml` with `ignore:`. Its legitimate keys, `self-hosted-runner.labels` and `config-variables`, are calibrations in our record.                                                                                                                                                                                     |
| pinact `.pinact.yaml` `ignore_actions`, ratchet `# ratchet:exclude`                                                                 | **Forbidden** by the same check. pinact runs only as a fixer, with its config generated from the record.                                                                                                                                                                                                                                                                                              |

This meets toolchain 4.3: "MUST forbid, through a defence of its own, every suppression route that
bypasses the project record". Every irreducible case goes to the record.

Rejected alternatives:

- **Keeping zizmor's and actionlint's own files with a comment rule** (the survey's sketch). That
  means two formats, regex ignores with no reason field, and zizmor cannot ignore a
  composite-action finding by config at all ("Composite action findings cannot be ignored via
  `zizmor.yml` currently"). The record would split across three files.
- **Inline comments carrying a record key.** That puts a suppression at the call site, which the
  method forbids.

### 8.2 The project record: `.github/actions-qa-ci.yml`

`actions-qa` loads this file itself (toolchain 6.1). It is validated against a JSON Schema that
ships in the binary.

```yaml
version: 1                     # the actions-qa-ci version this project pins; must equal the running binary (actionsqaci.versionPin)
analyse:
  include: [templates/github-actions/**]          # extra workflow/action files outside .github (see §10.2)
  unanalysed:                                     # workflow-shaped files deliberately not analysed
    - path: tests/fixtures/**
      justification: { hazard: "…", scope: "…" }
calibrations:
  selfHostedRunnerLabels: [gpu-large]
  shellcheckSeverity: warning
  enable: [actionsqaci.zizmor.concurrency-limits]  # opt-in audits from the pedantic set
templates:                                         # actionsqaci.templateDrift
  - source: templates/github-actions/php-qa-ci.yml
    copies: [.github/workflows/qa.yml]
requiredChecks:                                    # actionsqaci.requiredChecks
  - context: QA Pipeline
    branch: default
exceptions:
  - id: actionsqaci.zizmor.dangerous-triggers
    path: .github/workflows/release.yml
    job: release                                   # locator: job and/or step (id or name); line only as a last resort
    hazard: "workflow_run runs with the default branch's secrets after an untrusted run completes"
    scope: "Only this job, which checks out nothing from the triggering run and acts only on a SHA it re-verifies against the default branch"
    decision: "https://github.com/LongTermSupport/php-qa-ci/issues/NNN"
```

### 8.3 The justification lane (toolchain 6.2)

The first-party checks are:

- **`actionsqaci.unjustifiedException`.** Every exception, unanalysed path and narrowing must have
  `hazard`, `scope` and `decision`. There is no default.
  - The `hazard` and `scope` text is rejected when it matches the **documented generic-reason
    list**, which follows the *convention* of php-qa-ci's phpstanIgnoreJustification lane (a fixed
    list of generic phrases such as "needed for now", "legacy", "TODO", "temporary" and "false
    positive", and a minimum length of 40 characters). Rejecting "see above", the identifier
    repeated back, and text under a minimum word count are **new rules** actions-qa-ci adds; the
    php-qa-ci lane has none of them.
  - `decision` must be a URL or an `Owner:` reference.
  - The page states plainly that the check cannot verify truth.
- **`actionsqaci.staleException`.** An exception that matched no finding in a full run fails, so the
  record cannot rot after a fix.
- **`actionsqaci.disabledDefence`.** Disabling a bundled defence is an exception with no locator. It
  carries the same justification and is listed by `rules` as a narrowing.

Exceptions match on identifier + path + structural locator (job and step), not on line. Line
numbers drift on every edit, and a drifted line would silently re-target the exception.

---

## 9. First-party checks (Task 4.3)

| Identifier                                                                                      | Detects                                                                                                                                                                                                                                                                                                                                                                               | Origin                                                                                                                                                                                          |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `actionsqaci.templateDrift`                                                                     | Each declared copy is byte-identical to its source. A declared source or copy that is **missing** fails; it does not go quiet. Modes: `identical`, or `identical-except` with named YAML paths, for a consumer that legitimately varies one key.                                                                                                                                      | Generalises php-qa-ci's versionPins identity check, which goes inert when `qa.yml` is renamed ([ci-audit § 1.3](research/ci-audit.md#13-qayml-is-the-consumer-template-run-as-a-dogfood-check)) |
| `actionsqaci.lintCoverage`                                                                      | Every git-tracked file that is workflow-shaped (top-level `on:` + `jobs:`) or action-shaped (`action.y?ml` with `runs:`) is analysed, or is declared `unanalysed` with a justification. The analysed set is passed to zizmor and actionlint **explicitly**, because zizmor collects only `action.yml` from directories other than `.github/`.                                         | The survey found the templates were silently unscanned                                                                                                                                          |
| `actionsqaci.requiredChecks`                                                                    | Every `requiredChecks.context` equals the resolved `name:` (or the job id) of a job in a workflow triggered on `pull_request`. A name containing `${{ }}` or a matrix suffix cannot be a stable required check, and that is reported. When online, the lane also compares against the branch's readable `protection.required_status_checks.contexts`; offline, it is skipped by name. | Task 2.1 (`setup-branch-protection.bash` requires a context no workflow produces)                                                                                                               |
| `actionsqaci.inlineSuppression`, `actionsqaci.nativeSuppressionConfig`                          | §8.1                                                                                                                                                                                                                                                                                                                                                                                  | toolchain 4.3                                                                                                                                                                                   |
| `actionsqaci.unjustifiedException`, `actionsqaci.staleException`, `actionsqaci.disabledDefence` | §8.3                                                                                                                                                                                                                                                                                                                                                                                  | toolchain 6.2                                                                                                                                                                                   |
| `actionsqaci.versionPin`                                                                        | The record's `version` equals the running binary, so CI and local runs agree.                                                                                                                                                                                                                                                                                                         | Copies the versionPins idea                                                                                                                                                                     |
| `actionsqaci.agentSummaryCurrent`                                                               | The generated agent-summary region is current.                                                                                                                                                                                                                                                                                                                                        | toolchain 7.2                                                                                                                                                                                   |

**Bespoke project rules (D7).** Project rules live in `.github/actions-qa-ci/rules/*.yml`:

```yaml
id: phpqaci.noSelfHostedOnPullRequest
summary: "A job reachable from pull_request runs on GitHub-hosted runners"
doc: .github/actions-qa-ci/docs/phpqaci.noSelfHostedOnPullRequest.md   # required; the loader fails without it
select: job                       # workflow | job | step | uses | action-input
when: 'workflow.on.exists(e, e == "pull_request") && job.runsOn.exists(l, l == "self-hosted")'
message: "self-hosted runner reachable from pull_request"
fixtures: { red: [tests/red.yml], green: [tests/green.yml] }   # `actions-qa rule` runs both
```

- The engine is [cel-go](https://github.com/google/cel-go) (Apache-2.0). CEL is not
  Turing-complete, so a rule always terminates, and it evaluates over actionlint's parsed model.
  The selected node supplies the finding position.
- Rejected: **Rego/OPA**, which poutine uses. It is far larger, harder for a rule author to learn,
  and does not carry positions.
- Rejected: **yq/jq expressions**. They need a host binary and lose line positions.
- Rejected: **exec rules** (any program that emits our JSON). They would satisfy detector 4.1, but
  they re-introduce a host runtime. **[Owner]** whether to offer them later as an opt-in.

---

## 10. Adoption

### 10.1 Any repository

- **Install locally (one line):**

  ```sh
  curl -fsSLO https://github.com/LongTermSupport/actions-qa-ci/releases/download/vX.Y.Z/install.sh && sh install.sh
  ```

  Download-then-run, never `curl | sh`, and the script verifies the bundle hash. Then run
  `actions-qa install`. It writes a starter `.github/actions-qa-ci.yml` (`version:` pinned,
  `requiredChecks` empty) and the agent-summary region.

- **CI:** use the composite action at `LongTermSupport/actions-qa-ci@<sha> # vX.Y.Z`. It reads
  `version` from the record, runs the same `install.sh` logic, runs `actions-qa check --format github --sarif actions-qa.sarif`,
  and optionally uploads the SARIF (`upload-sarif: true` needs `security-events: write`). Its own
  `permissions` example is `contents: read`. Dependabot or pinact moves the action SHA; a bump in
  `version` without a matching action ref fails `actionsqaci.versionPin`.

- **Pre-push:** a documented `.git/hooks/pre-push` line, `actions-qa check --format agent`, scoped
  by the hook to changed files under analysed paths.

- **First run on an existing repository:** every finding is fixed, or the Owner records an
  exception. No baseline is generated; `actions-qa` has no baseline command at all.

### 10.2 What is analysed by default

The default set is `.github/workflows/*.y?ml`, `.github/actions/**/action.y?ml`, root
`action.y?ml`, `.github/dependabot.yml` (zizmor audits it), plus `analyse.include`.
`lintCoverage` turns anything else that is workflow-shaped into a finding.

### 10.3 php-qa-ci as a consumer (Task 4.4)

- **Pinning:** `actions-qa` is installed the way shellcheck is. A `vendor-bin/actions-qa-ci.version`
  file holds the version and the bundle sha256, and an installer verifies it (the shape of
  `src/ShellCheck/*`, generalised).
- **Lane:** `phpqaci.actionsQa` is a class under `src/Pipeline/`. It runs
  `actions-qa check --format json --sarif var/qa/actions-qa.sarif`, prints every identifier
  unaltered, and maps exit 1 to lane failure and exits 2/3/4 to the matching php-qa-ci outcomes.
  Exit 4 maps to the no-verdict / segfault halt.
  - It is on by default, and skipped by name when the project has no workflow or action files.
  - It is in the static phase.
- **Docs and listing:**
  - `bin/rule-doc actionsqaci.*` delegates to `actions-qa rule-doc`.
  - `bin/rules` and the CLAUDE.md active-defences region include `actions-qa rules --json`
    entries (toolchain 5.3, derived and not hand-copied).
  - actions-qa-ci's own agent-summary writer is **not** run in php-qa-ci consumers, so the region
    is not duplicated.
- **Record location:** php-qa-ci keeps the record at `.github/actions-qa-ci.yml`, actions-qa-ci's
  own path. It is not under `qaConfig/`; one tool has one record path.
  - php-qa-ci's own record declares `templates/github-actions/**` in `analyse.include`, the
    `templates` pairs (replacing the versionPins identity check, which then retires), and
    `requiredChecks: QA Pipeline`.
  - `scripts/setup-branch-protection.bash` reads its contexts from that record, replacing its
    hard-coded `OUR_CONTEXTS` (Task 2.1 fixed the stale value; this removes the hard-coding).
- **Shipped template:** `templates/github-actions/php-qa-ci.yml` needs no extra job, because the
  lane runs inside `bin/qa`.
- **Changelog:** adding the lane is consumer-visible, so it gets an `## Unreleased` entry.

---

## 11. Taken from php-qa-ci as conventions (not code)

No PHP code is reused. These are re-expressed in Go:

- **The identifier style.** `<ns>.<camelName>` for first-party checks, a stable constant per rule,
  and a test that bans literal identifiers.
- **One page per identifier** stating the correct construction, a generated index, and a self-audit
  that blocks the release.
- **The trio of commands:** `rules` (derived, project record included), `rule-doc` (offline) and the
  harness (`rule`).
- **The generated agent-summary region,** refreshed on install and checked for currency.
- **The justification lane,** including its documented generic-reason list, and stale-exception
  failure.
- **Not a tool proxy.** **Writable by default** with a read-only switch. **Segfault and signal
  halt** with a distinct no-verdict exit.
- **Changelog-driven releases.** An `## Unreleased` entry or a `Changelog: none — <reason>`
  trailer. A green push to the default branch opens the release PR, and the tag is never chosen by
  hand.
- **Tool currency:** a daily update, a PR or a failure issue, fix-red-under-DBF, and the standing
  merge after verification **[Owner]**.
- **The DBF conformance declaration** at two levels (artefact and project), with `known-gaps`.
  There is no `composer.json` here, so it lives in `dbf.json` at the repository root and is also
  printed by `actions-qa version --json`. A test pins it to the vendored specification versions.
- **Working conventions:** `--no-ff` merges, branch-name policy, the pre-push battery, the plan
  workflow, and knowledge kept in `CLAUDE/*.md`.

---

## 12. Self-check CI of actions-qa-ci

| Workflow                                | Trigger                                      | Content                                                                                                                                                                                                                                                                                                                         |
| --------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ci.yml` (job `CI`, the required check) | `pull_request`; push to the default branch   | `go vet`, golangci-lint (pinned), `go test` (unit + fixture corpus: a red and a green fixture per rule, plus a valid-YAML corpus that no rule may fire on or crash on), self-audit (§6.3), build the bundle, then run **`actions-qa check` on this repository with the just-built bundle** (toolchain 8.2) and upload its SARIF |
| `release.yml`                           | `workflow_run` of `CI` on the default branch | Changelog-driven: the release PR, then the tag, bundles, `checksums.txt`, attestations, SBOM, a per-version `install.sh`. It carries a recorded `dangerous-triggers` exception, in the same shape as php-qa-ci's                                                                                                                |
| `update-tools.yml`                      | daily, `workflow_dispatch`                   | §5.3. The build-and-test job is read-only; a separate job holds `contents`/`pull-requests: write` and only opens the PR (the update-deps split from Task 3.3)                                                                                                                                                                   |
| `dogfood.yml`                           | `pull_request`                               | Runs the built bundle over a pinned php-qa-ci checkout and over a corpus of public workflows. It fails on exit 2, 3 or 4 (a usage or record fault, a crash or no verdict), not on findings                                                                                                                                      |

Every workflow has `permissions: {}` at the top with per-job grants, `concurrency` with
cancel-in-progress (except release), SHA-pinned `uses:`, and `persist-credentials: false`, and
passes its own checks.

The initial `dbf.json` `known-gaps`:

- `pyflakes` is not run (§2.3);
- the v1 zizmor provenance (§5.2), if (b) is deferred;
- the 4.1 reading (§3), until the Owner confirms it.

---

## 13. Bootstrap phases for the new repository (Task 4.2)

Each phase ends green on the repository's own CI.

1. **Repository floor.**
   - Go module `github.com/LongTermSupport/actions-qa-ci`.
   - `README.md`, `CLAUDE.md` (the binding floor adapted), `CHANGELOG.md` with `## Unreleased`,
     `dbf.json`.
   - Branch policy, and a `ci.yml` running `go vet`/`go test`.
   - Default branch `main` with one release line. **[Owner]**
2. **Core.**
   - The finding model, the registry with identifier constants, the output formats (text, agent,
     json, github, single-run SARIF), and the exit codes.
   - `rules`, `rule-doc`, `rule`, the generated index, the self-audit test, and the literal-identifier ban.
3. **actionlint in-process + pinned shellcheck.**
   - The manifest, a dev fetcher, and runtime hash verification.
   - Pages for the 17 kinds, and the shellcheck family-page decision (§6.4).
   - The ownership drops (§4.2) with fixtures.
4. **zizmor backend.**
   - The static musl wheel in the manifest, a generated `--config`, `--no-ignores`, and online
     audits skipped by name.
   - Pages for every audit the pinned version can emit (41 at v1.31.1).
5. **The project record and the justification lane.**
   - Schema, loader and exception application.
   - `unjustifiedException`, `staleException`, `disabledDefence`, `inlineSuppression`,
     `nativeSuppressionConfig` and `versionPin`.
6. **First-party checks (Task 4.3) and the CEL engine:** `templateDrift`, `lintCoverage`,
   `requiredChecks`, and project rules with their harness.
7. **Distribution.**
   - The bundle build, `install.sh`, the composite action and the changelog-driven `release.yml`,
     with attestations and SBOM.
   - `fix` (pinact plus zizmor `--fix=safe`).
   - The agent-summary writer and `agentSummaryCurrent`.
   - An optional OCI image.
8. **Currency:** `update-tools.yml`, the `update-tools-failure` issue flow, and the `CLAUDE/tool-currency.md` equivalent.
9. **Dogfood and first consumer (Task 4.4):** `dogfood.yml`, then php-qa-ci's `phpqaci.actionsQa`
   lane, with every php-qa-ci finding fixed or recorded as an Owner exception (Task 2.2).

---

## 14. Owner decisions needed

1. **Conformance reading (§3).** Is actions-qa-ci, with zizmor and actionlint as backends, the
   Detector that hosts bespoke rules? Or are their findings non-Defence checks? Consider raising
   this with the specification's author.
2. **zizmor provenance (§5.2).** Is the PyPI musl wheel pinned by sha256 acceptable for v1, or must
   we self-build and attest from source?
3. **Release cooldown (§5.3).** A minimum release age (3 days proposed) before a tool update is
   adopted, versus php-qa-ci's same-day policy.
4. **Standing merge authorisation (§5.3).** Do green `update-tools` PRs get merged after a fresh
   sub-agent's verification, as in php-qa-ci?
5. **Default severity and persona.** The defaults are zizmor `regular` (pedantic and auditor audits
   are opt-in per identifier) and shellcheck `warning`. A default narrower than upstream's full
   catalogue is a toolchain default under toolchain 6.4, but it is the Owner's call.
6. **Platforms (§2.3).** Is v1 Linux amd64 and arm64 only, or are darwin and armv7 in?
7. **Repository shape.** Default branch `main` and a single release line.
8. **Exec rules (§9).** Offer later an opt-in rule kind that runs a project program, for checks CEL
   cannot express? It brings a host runtime back.
9. **The first exception.** `release.yml` `dangerous-triggers` (`workflow_run`) in php-qa-ci and
   the new repository: record it as an exception with the hazard and scope stated in §8.2, or
   restructure release gating.
