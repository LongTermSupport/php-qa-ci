# Phase 0 — Mago behaviour facts (Tasks 0.1, 0.2, 0.4)

Binary: `untracked/scratch/mago-research/bin/mago` (`mago 1.57.0`). Scratch:
`untracked/scratch/mago-phase0/` (fixtures `t01/`, `t02/`, generators `gen_*.py`, `segv_probe.php`).
`M` below is the binary path. Every probe passes `--config <file>` explicitly. Output is trimmed
to the lines that matter; ANSI colour codes are removed.

## STOP: a real Mago segfault (binding floor "Segfaults halt")

While I was probing Task 0.2 crash behaviour, **Mago 1.57.0 segfaulted on its own** on a
generated file: a single statement `$x = 'a' . 'a' . … ;` with 100,000 operands
(`t02/crash/q-concat-100000.php`, 600,037 bytes, sha256 `458c31e8…2e03d7`, from
`gen_nested2.py <out> 100000 concat`).

```
$ timeout 120 $M --config ../ok.toml lint q-concat-100000.php --reporting-format code-count
/bin/bash: line 1: 921214 Segmentation fault ...   -> exit=139, stdout empty, "dumped core"
$ timeout 120 $M --config ../ok.toml analyze q-concat-100000.php ...
/bin/bash: line 1: 921369 Segmentation fault ...   -> exit=139
```

These were at about 22:04Z on the host, PIDs 921214 (lint) and 921369 (analyze). The same shape
with 5,000 operands is clean (exit 0). Per `CLAUDE/segfault-policy.md` I **halted all further
Mago execution at that point**: I did not re-run it, reduce it or theorise about it. The other
tasks continued only with work that does not run Mago (GitHub API, the sha256 check, attestation,
reading code). I could not read the kernel log (`dmesg` exit 1). `core_pattern` pipes to the
host's `systemd-coredump`, so the cores are on the host.

Separately, I sent SIGSEGV to Mago on purpose with `kill`. The self-signalled PIDs (919154,
920116 and the `timeout -s SEGV` runs) also dumped core on the host. They are not defects, and
anyone reading the host's cores needs to tell the two apart.

**Owner decision needed:** debug, file and defend. The defect is in Mago (Rust), so it would be
filed with carthage-software, not php-src, and filing needs authorisation for each report.

## Task 0.1 — "everything off except an allow-list"

### Linter: facts

1. **No default-off or "only" key in config.** `LinterConfiguration` has exactly `excludes, integrations, rules, baseline, baseline-variant, minimum-fail-level`
   (`src/config/linter.rs`; confirmed by the unknown-key error below). Each rule defaults to
   `enabled: C::default_enabled()` (`crates/linter/src/settings.rs`,
   `impl Default for RuleSettings`). The docs (`tools/linter/configuration-reference.md`) list
   only per-rule `enabled/level/exclude`. There is no category, preset or wildcard disable.
   The 1.57 catalogue has 190 rules, 113 of them on by default
   (`lint --pedantic --list-rules --json` gives 190; `lint --list-rules --json` with no config
   gives 113).

2. **An allow-list in toml works only by listing every rule.** Probe:
   `python3 -I gen_allowlist.py all-rules-pedantic.json allow.toml no-empty no-goto` writes all
   190 rules, 188 of them `{ enabled = false }`. The fixture `t01/fixture.php` violates
   `no-empty`, `no-goto` and `no-error-control-operator` (`@`):

   ```
   $ $M --config empty.toml lint fixture.php      # defaults
   error[no-empty], note[no-goto] x2, error[no-error-control-operator], help[function-name] x3   exit=1
   $ $M --config allow.toml lint fixture.php
   error[no-empty]; error[no-goto] x2  -> "found 3 issues"   exit=1   (the @ rule does not fire)
   ```

3. **A rule missing from the toml runs at its default.** This is what a new release adding a
   rule looks like. `allow-missing-one.toml` (allow.toml minus its `no-error-control-operator`
   line) brings `error[no-error-control-operator]` back, exit=1. A new default-on rule is
   therefore on unless the toml names it. In the two samples I found, new rules shipped
   opt-in: `prefer-datetimeimmutable-create-from-timestamp` (1.45; not in the 1.57
   default-on list) and `prefer-self` (1.58.0 notes: "adds an opt-in rule"). Mago states no
   policy on this (**UNVERIFIED as a policy**; CONTRIBUTING.md and the docs say nothing).

4. **An unknown or renamed rule name in the toml fails closed.** It exits 2 with
   `Failed to parse configuration file … unknown field 'no-such-rule'`, and so do an unknown
   rule option (`bogus`), a bad level (`"fatal"`) and a wrong type (`enabled = "yes"`). In the
   1.58.2 source (read, not run), keys containing `/` are now accepted as extension rules, while
   native keys still go through `deny_unknown_fields`.

5. **The CLI `--only` is a real allow-list** ("Run only specific rules, ignoring the
   configuration file"). It runs only the named rules, even ones the config disables
   (`--only no-error-control-operator` with allow.toml → that finding, exit 1). It still applies
   the config's levels and options: `--only no-goto` gives `error` with allow.toml and `note`
   with defaults, and `assertion-style` with `style="self"` → "No issues found". **But it fails
   open on an unknown name:**

   ```
   $ $M --config allow.toml lint --only no-such-rule fixture.php
   WARN No rules found for the specified 'only' filter: ["no-such-rule"]
   INFO No issues found.                                                     exit=0
   ```

   `--only no-empty,no-such-rule` runs `no-empty` and says nothing about the bad name.

6. **Reading the effective set E (for I5).** `lint --list-rules --json` reports each enabled
   rule's **default** level, not the configured one: with allow.toml it shows `no-goto: Note`,
   although the config sets `error` and the run reports error. `mago --config allow.toml config --show linter` gives the effective `{"no-goto": {"enabled": true, "level": "Error"}, "no-empty": {…"Error"}}`, filtered to the 152 rules that apply under the configured PHP
   version and integrations.

7. **Parse errors are always reported**, whatever the rule selection: `error[parse]`, exit 1,
   with allow.toml, with `--only no-empty` and with `-s`.

### Analyser: facts

1. **No per-code enable or level, and no allow-list in config.** The `[analyzer]` keys are the
   feature flags plus `ignore`, `excludes`, `baseline*`, `minimum-fail-level` and `plugins`
   (`config --show analyzer --default`). Codes: `analyze --list-codes` → 331. `ignore` is a deny
   list of codes, `{code,in}` or `{pattern}`. It uses the Rust `regex` crate (docs), which has
   no look-around, so "ignore everything except X" cannot be written as one pattern.
   **`ignore` accepts unknown codes silently:** `ignore = ["no-such-code", "non-existent-function"]`
   → no warning, exit 1 on the remaining `invalid-argument`.
2. **The CLI `--retain-code` gives an allow-list that also governs the exit code.** On
   `t01/analyse.php` (errors `invalid-argument`, `non-existent-function`; note
   `unused-statement`), `--retain-code invalid-argument` → exit 1 and
   `--retain-code unused-statement` → 1 note, **exit 0** (the errors that were filtered out
   no longer fail the run). All analyses still run, so it is no faster. **It fails open on an
   unknown code:** `--retain-code no-such-code` → `WARN No issues found matching code(s)`,
   exit 0. The same flag exists on `lint`.

### Recommendation (separate from the facts)

- Recommendation 5 holds in a modified form. **The config cannot express "default off".** Ship
  a toml that enumerates every rule, generated from `lint --pedantic --list-rules --json` so it
  is never hand-maintained. An unknown name then fails closed (exit 2), and a new default-on
  rule is caught by I5 reading `config --show linter`, not `--list-rules`, whose levels are
  wrong. Optionally add `--only <E>` on the command line as a second belt, but only after the
  wrapper has checked every `--only` name against `--pedantic --list-rules --json`, because
  `--only` and `--retain-code` silently accept unknown names. For a later analyser delivery,
  `--retain-code` is the allow-list mechanism, with the same name check.

## Task 0.2 — exit codes and fail-open behaviour

All runs use `--reporting-format short` unless stated otherwise. `ok.toml` is
`php-version = "8.5"`.

| Case                                                                                                            | Exit                  | Output                                                                                                 | Fails closed?                                                                         |
| --------------------------------------------------------------------------------------------------------------- | --------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------- |
| Clean file (lint and analyze)                                                                                   | 0                     | `INFO No issues found.`                                                                                | —                                                                                     |
| Error-level finding                                                                                             | 1                     | findings                                                                                               | yes                                                                                   |
| Warning-only finding, default `minimum-fail-level`                                                              | **0**                 | `warning[assertion-style]`                                                                             | warnings pass by default                                                              |
| Same with `-m warning`                                                                                          | 1                     |                                                                                                        | yes                                                                                   |
| PHP syntax error                                                                                                | 1                     | `error[parse]` ×3 (lint, analyze, `-s`, allow-list, `--only`)                                          | yes, but **same code as findings**                                                    |
| Non-UTF-8 / binary bytes                                                                                        | 1                     | `error[syntax]`                                                                                        | yes                                                                                   |
| Valid PHP nested past about 512 levels (parens 600–100,000; `php -l` accepts 1,000)                             | 1                     | `error[parse]` (a depth limit, not a crash)                                                            | yes, but rejects valid PHP                                                            |
| Unreadable file (DAC caps dropped: `setpriv --bounding-set -dac_override,-dac_read_search`; `cat` is denied)    | **2**                 | `ERROR Orchestrator error: Database error: I/O error: Permission denied (os error 13)`, file not named | yes; one unreadable file in a directory also gives 2                                  |
| Same as plain root                                                                                              | 0                     | root reads mode 000                                                                                    | n/a                                                                                   |
| **Nonexistent path argument** (file or dir)                                                                     | **0**                 | `WARN Failed to walk … Entry will be skipped.` `INFO No files found to lint.`                          | **NO**                                                                                |
| **Dangling symlink**                                                                                            | **0**                 | same WARN                                                                                              | **NO**                                                                                |
| **Directory named `dir.php`**                                                                                   | **0**                 | `No files found to lint.`                                                                              | **NO**                                                                                |
| **File excluded by `[source] excludes`, passed explicitly**                                                     | **0**                 | `No files found to lint.`                                                                              | **NO** (a silent no-op)                                                               |
| Missing path plus a real file                                                                                   | 0 / per the real file | WARN on stderr, JSON for the rest                                                                      | **partial silent skip**                                                               |
| `.inc` file passed explicitly                                                                                   | linted (1)            |                                                                                                        | —                                                                                     |
| Bad TOML / unknown top-level key / unknown `[linter]` or `[analyzer]` key / unknown rule / bad level / bad type | 2                     | `ERROR Failed to parse configuration file …`                                                           | yes                                                                                   |
| `--config` file missing                                                                                         | 2                     | `Failed to read configuration file`                                                                    | yes                                                                                   |
| `--config /dev/null`                                                                                            | 2                     | unsupported extension                                                                                  | yes                                                                                   |
| Config `version = "2"` (major pin)                                                                              | 2                     | refuses to run                                                                                         | yes                                                                                   |
| Config `version = "1.50.0"` (minor drift)                                                                       | 0                     | WARN only                                                                                              | no, unless `version-drift-fail-level = "patch"` → 2 (undocumented key; values \`major |
| Killed by SIGSEGV (`kill`; simulated)                                                                           | 139 / signaled        | stdout empty                                                                                           | see note                                                                              |
| **Real segfault** (100k-operand concat)                                                                         | **139**               | stdout empty                                                                                           | yes, but see STOP above                                                               |

- **Reporting formats.** `json, checkstyle, sarif, github, gitlab, count, code-count, emacs` all
  exit 1 on findings and 0 when clean; the format does not change the exit code. JSON on a clean
  run is `{"issues": []}` on stdout, and logs go to stderr. **When no file is found, stdout is
  empty, even with `--reporting-format json`**: no JSON document is written. JSON
  `span.start.line` is 0-based (short format line 9 = JSON `"line": 8`).
- **SIGSEGV seen from a parent** (`segv_probe.php`, Symfony Process). The *first* `kill -SEGV`
  is absorbed: the process keeps running and in two of three `timeout -s SEGV` runs it
  finished normally with exit 1. The second signal kills it, giving
  `getExitCode=139 hasBeenSignaled=true getTermSignal=11 stdoutBytes=0`. An externally sent
  SIGSEGV is therefore not a reliable crash simulation. My inference, **UNVERIFIED**: Rust std's
  stack-guard SIGSEGV handler swallows one signal.
- **Config discovery.** Without `--config`, Mago falls back to `$XDG_CONFIG_HOME`,
  `~/.config/mago.toml` and `~/mago.toml`. An `extends` directory entry with no config file in
  it is "skipped with a warning rather than failing" (`guide/configuration.md`).
- **Not tested** (halted): a Rust panic (normally exit 101), and an out-of-memory kill.

**Recommendation for the wrapper and I7.** Treat only `0` with a parseable JSON document, or `1`
with a parseable JSON document, as a result. Treat everything else as broken: exit 2, a signal,
or empty or unparseable stdout. Treat `code == "parse"` / `"syntax"` as a parse failure, not a
finding. Check before calling that every path argument exists, is a regular file and is not
excluded by config, because Mago passes on all four silently. Always pass `--config`, and pin
`version = "<exact>"` with `version-drift-fail-level = "patch"`.

## Task 0.4 — install-time delivery

### Facts

- **Digests.** There are **no checksum or signature files** among the release assets: 18
  assets for 1.58.2, all `.tar.gz`/`.zip` plus source. Every asset carries GitHub's API
  `digest` field (`gh api repos/carthage-software/mago/releases/tags/1.57.0` → `"digest": "sha256:eb9ae2c0…f0d336"` for `mago-1.57.0-x86_64-unknown-linux-musl.tar.gz`). It matches
  `sha256sum` of the downloaded tarball exactly. The last 10 releases have no asset without a
  digest. GitHub computes that field at upload. It proves the bytes match what was uploaded, not
  who built them (GitHub behaviour, not re-verified here).
- **Releases move fast and can be incomplete.** The latest is now **1.58.2**, published at
  20:43Z; 1.58.0 and 1.58.1 came out the same evening. **1.58.0 shipped 15 assets with no
  `x86_64-unknown-linux-musl` (or macOS) tarball.** An installer has to treat a missing asset
  for the platform as an error, never fall back to another one.
- **Tarball layout:** `mago-<v>-<target>/{mago,LICENSE-APACHE,LICENSE-MIT,README.md}`, with the
  binary at 31,348,080 bytes and mode 0755.
- **Attestation passes:**
  `gh attestation verify untracked/scratch/mago-research/dl/mago-1.57.0-x86_64-unknown-linux-musl.tar.gz --repo carthage-software/mago` → exit 0.
  `--format json` gives one result: Sigstore bundle v0.3, predicate `slsa.dev/provenance/v1`,
  `buildSignerURI=…/carthage-software/mago/.github/workflows/cd.yml@refs/tags/1.57.0`,
  `sourceRepositoryRef=refs/tags/1.57.0`, issuer `token.actions.githubusercontent.com`, and a
  subject digest equal to the asset digest. Pinned with
  `--signer-workflow carthage-software/mago/.github/workflows/cd.yml --source-ref refs/tags/1.57.0`
  → exit 0. A **tampered copy** (one byte appended) → exit 1 (`HTTP 404` on attestation lookup
  by its digest).
- **The bundle is public without authentication:**
  `curl https://api.github.com/repos/carthage-software/mago/attestations/sha256:<digest>` → 200
  with `{attestations:[{bundle:{mediaType, verificationMaterial{tlogEntries, timestampVerificationData, certificate}, dsseEnvelope}}]}`. `gh` also supports offline
  verification (`gh attestation download`, `trusted-root`, `verify --bundle --custom-trusted-root`).
- **Verifying from PHP (Packagist search, not exercised).** `thephpf/attestation` 0.0.8 (The
  PHP Foundation, 36,677 downloads) does `DownloadGitHubBundle` plus `VerifyBundleWithOpenSsl`
  with an identity policy (source repository, workflow, issuer). It requires
  `composer/composer`, `symfony/console` and `webmozart/assert`. `k2gl/sigstore-verify` 1.6.1
  (2,077 downloads) is an "offline Sigstore bundle verifier" needing `phpseclib`, `k2gl/tuf` and
  others. **UNVERIFIED** whether either verifies Mago's bundle: neither was installed or run
  here. Both are pre-1.0 or low-adoption.
- **ShellCheck today** (`src/ShellCheck/ShellCheckInstaller.php`, `InstallDecider.php`,
  `bin/shellcheck-install`, `scripts/tool-install.bash` Phase 3). The binary is **committed**
  under `vendor-bin/`. Install mode never downloads: a missing binary or pin is `Broken`, which
  prints ERROR and exits 1 (loud). Update mode runs only for maintainers (gated on `phive`
  being installed). It fetches `releases/latest` over PHP streams, downloads the `.tar.gz` and
  extracts it with `PharData`, shelling out nothing. **It verifies no digest or attestation.**
  An unreachable API in update mode keeps the pin (`Ready`, exit 0, with a "Note:" on stderr).
  `composer.json` scripts call `tool-install.bash` on post-install and post-update, but
  **those scripts run only when php-qa-ci is the root package**.
- **The closer prior art is PHPStan Turbo**, which is not committed and is fetched at install
  time (`src/Turbo/TurboInstaller.php`, `bin/turbo-install`, tool-install Phase 6,
  `src/ComposerPlugin/TurboInstallPlugin.php`). A committed manifest
  (`vendor-phar/turbo-ext.json`) pins the sha256. Install fetches the host's file, refuses a
  digest mismatch (exit 1), stages it and renames it atomically, and stamps what it installed.
  The binary directory is gitignored. Consumers get it through the **Composer plugin** on
  POST_INSTALL/POST_UPDATE, which runs `bin/turbo-install install` as a subprocess. **Network
  failure is a soft skip:** "WARNING: could not download … PHPStan runs without Turbo", exit 0.
  The plugin also turns any non-zero exit into a `<comment>`, so a consumer's
  `composer install` never fails. That is right for Turbo, which is optional, and is exactly
  what D6 forbids for Mago.

### Recommendation (separate from the facts)

1. **Model the Mago fetch on Turbo, not ShellCheck:** a decider, an installer and a Composer
   plugin. Commit a `vendor-bin/mago.json` holding `{version, target-asset-name: sha256}` per
   supported platform, at least `x86_64-unknown-linux-musl` and `aarch64-unknown-linux-musl`.
   The values come from the release API `digest` field at update time. Download over PHP
   streams, compare with `hash_equals`, extract with `PharData`, stage beside the binary and
   rename it into place, and write a stamp file.
2. **Diverge from Turbo on failure, per D6.** No network, a missing asset or a digest mismatch
   must make the installer exit non-zero, **and** the plugin must not downgrade that to a
   comment: `throw`, or `$io->writeError` plus a non-zero result. The lane and the lint wrapper
   must also fail loudly at run time if the binary or its stamp is missing ("run composer
   install"), so an install that was skipped can never read as "no findings".
3. **Attestation in the update workflow, not at consumer install.** In `update-deps.yml`, run
   `gh attestation verify <tarball> --repo carthage-software/mago --signer-workflow carthage-software/mago/.github/workflows/cd.yml --source-ref refs/tags/<v>` before the
   digest is committed. The committed sha256 then carries that provenance to every install.
   Adopting `thephpf/attestation` to verify at install time from PHP is possible but untested,
   and it adds `composer/composer` as a runtime dependency. Treat it as a separate decision.
4. **The update step refuses a release with no asset for a platform** (as 1.58.0 would have) and
   **refuses a major bump** pending the Owner (D8). Because of the segfault above, the first
   update should also wait until the Owner has ruled on that.
