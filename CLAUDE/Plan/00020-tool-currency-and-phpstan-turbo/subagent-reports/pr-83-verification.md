# PR #83 verification: PHPStan Turbo (Plan 00020 Tasks 3.1, 3.2, 3.3, 3.5)

- Pull request: LongTermSupport/php-qa-ci#83, `feature/plan-00020-phpstan-turbo` into `php8.5`
- Head verified: `1eb9dca360fc72d2947089357ebc5e5f7b8557cb`; base read: `origin/php8.5` at `b81709b7`
- Verifier: fresh general-purpose sub-agent, read-only towards the PR. Reproduction ran in a throwaway
  detached worktree `untracked/worktrees/verify-83` (`composer install --no-scripts --no-interaction`,
  plugins enabled), since removed. Scratch under `untracked/scratch/v83/`.
- Each finding is marked **[reproduced]** or **[read]**.

## Blocking findings

### B1. `bin/turbo-install` overwrites the live `.so` in place; a running PHPStan crashes with SIGSEGV / SIGBUS [reproduced]

`src/Turbo/TurboInstaller.php:189` stages the download in `TemporaryDirectory::create()`, i.e. under
`sys_get_temp_dir()`, and `src/Turbo/TurboInstaller.php:200` moves it into place with `\Safe\rename()`.
When the temp dir and `vendor-phar/` are on different filesystems (as on this host: `/tmp` is the
container overlay, `/workspace` is `/dev/sda3`; the same is true of any Docker setup with a bind-mounted
project), PHP's `rename()` falls back to copy-into-the-existing-file + unlink. The target inode is
truncated and rewritten in place, not replaced:

- inode of `vendor-phar/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so` before and after a forced
  reinstall (stamp deleted, `php bin/turbo-install install`): `308969035` both times.

A process that has the old `.so` mapped then faults on pages that were truncated away. Reproduced:
`phpstan.phar analyse src tests` (Turbo loaded, cold cache) while `bin/turbo-install install` re-ran
with the stamp removed:

| run | PHPStan exit | reinstalls during the run |
| --- | --- | --- |
| 1 | 139 (Segmentation fault, core dumped) | 15 |
| 2 | 135 (Bus error, core dumped) | 5 |
| 3 | 135 (Bus error, core dumped) | 3 |

Control, same analysis, same number of replacements but done atomically (`cp` to a sibling temp file,
`mv -f` over the target, new inode): 3 of 3 runs completed normally (exit 1 on the deliberate
`tests/assets/.../ParseError.php` fixture, no internal errors, no crash).

When it bites in practice: whenever the stamp differs from the manifest, i.e. the first
`composer install`/`update` after a php-qa-ci release that moves `phpstan.phar`, or a missing stamp,
while a qa run (another session, another agent, an IDE PHPStan) is running against the same
`vendor/lts/php-qa-ci`. The plugin makes this automatic in every consuming project. Under the
binding segfault policy this is a halt condition, not a note. I could not read the kernel log
(`dmesg` is not permitted in the container), so the host-side `segfault at` lines and cores should be
requested from the host if the coordinator files it.

The same code also leaves, during the copy window, a partly written `.so` that a starting PHPStan may
load; and on cross-device copy a pre-existing symlink at the target would be followed rather than
replaced.

Fix shape (after its red test, per Defence Before Fix): stage inside `dirname($binary)` (a sibling temp
name), chmod there, then `rename()` over the target, which is then a same-filesystem atomic replace; a
detector could be a test that asserts the installed binary gets a new inode / that staging is in the
target directory, or a PHPStan rule against `rename()` from a temp directory into a published path.

## Non-blocking notes

1. **[read] Red-first ordering is not complete for every behaviour.** `93d191a` (TurboPlatform,
   TurboManifest, TurboInstallDecider) adds its tests in the same commit as the code; the 0644 chmod
   (`2901b4e`) fixes an observed defect (world-writable extracted binary) with its assertion added in
   the fixing commit, not a red commit first. The table's four pairs themselves are honest (below).
2. **[read] A version bump without a matching turbo-ext release blocks every run.** If a maintainer
   update moves `phpstan.phar` to a version phpstan/turbo-ext has not tagged yet, `refreshManifest`
   (`TurboInstaller.php:106`) warns and keeps the old manifest, the decider then returns Broken
   (exit 1, which `scripts/tool-install.bash` will surface), and `PharToolsVerifier` refuses the whole
   pipeline on the version mismatch. That effectively decides "Turbo missing is fatal" at the manifest
   level while D2 is still open. Worth an explicit Owner look alongside D2.
3. **[read] A malformed manifest is not reported as a preflight refusal.**
   `PharToolsVerifier.php:62` calls `TurboManifest::fromJson()`, which throws
   `UnexpectedValueException` / `JsonException`, not `MissingPharException`; it surfaces as whatever the
   preflight does with an unexpected exception rather than the guided message the missing/mismatch cases
   get. No test covers a corrupt manifest at preflight.
4. **[reproduced] The stamp file is created with the process umask** (`0600` here) while the binary is
   forced to `0644`. Harmless (only the installer reads it), but inconsistent.
5. **[read] Freshness is decided by the stamp only.** A binary replaced or corrupted on disk with the
   stamp intact is never re-verified. Acceptable, since the digest is checked at download time, but
   the docs say "pinned by digest" and the check is at install, not at load.
6. **[read] 2 test edits in fix commits** (`eed543c`: `hash()` call wrapped in a helper; `455b841`:
   `addToAssertionCount(1)` replaced by `#[DoesNotPerformAssertions]`, `expectExceptionMessage` by
   `expectExceptionMessageIsOrContains`). None weakens an assertion: the "passes" cases still fail on
   any exception.

## Checks

### 1. Defence before fix [reproduced]

Each red commit's test run at the red commit and at the fix commit (`bin/phpunit -c qaConfig/phpunit.xml --no-coverage <file>`):

| Pair | Red result | Fix result |
| --- | --- | --- |
| 3.1 `ec699c3` -> `2901b4e` (after `bin/turbo-install install`) | FAIL, for the stated reason: diagnose reports `Turbo extension: not loaded`, `Turbo worker binary: none found` | PASS |
| 3.2 installer `7680332` -> `eed543c` | 11 errors: `Interface "LTS\PHPQA\Turbo\TurboReleaseSourceInterface" not found` (the installer does not exist yet) | PASS |
| 3.2 plugin `5fba9aa` -> `075e71b` | 1 error + 1 failure: `TurboInstallPlugin` class not found; composer.json `extra.class` lacks it | PASS |
| 3.3 manifest `88d5b99` -> `455b841` | 2 failures: `MissingPharException` not thrown (missing manifest, mismatched version) | PASS |

At the head: `tests/Small/Turbo` OK (37 tests), `tests/Small/Pipeline/Runner` OK (47 tests).

### 2. Installer security and integrity

- **SHA-256 not bypassable [read + reproduced]:** the digest compared is the manifest's, which
  `TurboManifest::fromJson` only accepts as 64 lowercase hex (an empty or malformed hash rejects the
  whole manifest, exit 1). An asset with no manifest entry is `Unsupported`: nothing is fetched or
  installed. Comparison is `hash_equals` over the downloaded bytes, before anything is written; a
  mismatch exits 1 and writes nothing (covered by `aDownloadWhoseDigestDiffersFailsAndPlacesNothing`).
- **Redirect / HTML error page [read + reproduced]:** `ignore_errors => false`, so a 4xx/5xx is a
  failed request (warning, exit 0, nothing installed); a 200 HTML page fails the digest (exit 1).
  The real download follows GitHub's redirect to its asset host and verified correctly.
- **Zip extra entries / path traversal [read]:** only the named entry `phpstan_turbo.so` is extracted,
  into a fresh staging directory, and only after the archive's digest matched the pin, so the archive's
  content is fixed by the manifest.
- **Pre-existing file at the target:** replaced — but not atomically across filesystems: see **B1**.
- **Permissions [reproduced]:** binary `0644`, directory `0755`; stamp follows umask (note 4).
- **Token scope [reproduced]:** `GITHUB_AUTH_TOKEN` is only attached to requests for the two hard-coded
  `api.github.com` / `github.com` URLs. With a local two-host redirect (127.0.0.1 -> localhost) driven
  through the real `GitHubTurboReleaseSource::get()`, PHP's HTTP wrapper did **not** forward the
  `Authorization` header to the redirect target (`AUTH=(none)`, while `Accept` was forwarded). So the
  token does not follow GitHub's redirect to its asset CDN either.
- **Nothing executed from the download [read]:** the `.so` is only written; nothing is `dl()`ed,
  `include`d or run by the installer.

### 3. Composer plugin [read]

`src/ComposerPlugin/TurboInstallPlugin.php` calls only global functions (`is_string`, `is_file`,
`escapeshellarg`) and Composer's API (`ProcessExecutor`, IO, Config). It returns early when the root
package is `lts/php-qa-ci`, and when `bin/turbo-install` is absent. Both arguments are
`escapeshellarg`-quoted; the mode is a literal. A non-zero exit is written as a `<comment>` and never
thrown, so a consumer's install never fails on Turbo. Registered in `composer.json` `extra.class`.
(It is the vehicle for B1 in consuming projects.)

### 4. Manifest correctness [reproduced, with network]

- `vendor-phar/turbo-ext.json` says `2.3.0`; `phive.xml` records `installed="2.3.0"`;
  `php vendor-phar/phpstan.phar --version` prints `2.3.0`.
- All 24 manifest entries match the `digest` fields of the live GitHub release API for
  phpstan/turbo-ext tag `2.3.0`; the only release assets not in the manifest are the 8 Windows
  `-vs16`/`-vs17` builds, excluded by design.
- Independently downloaded and hashed: `php8.4-arm64-linux-musl` (`1063d524…`) and
  `php8.6-x86_64-linux-glibc-zts` (`69568dbc…`) match; the installer's own download of
  `php8.5-x86_64-linux-glibc` stamped `62c7222f…`, matching. Each zip holds exactly one entry,
  `phpstan_turbo.so`.

### 5. Turbo loads from the installed path [reproduced]

After `php bin/turbo-install install` in the throwaway worktree, `php vendor-phar/phpstan.phar diagnose`:
`Turbo extension: enabled (version 6351afb)`, worker binary
`.../vendor-phar/turbo-ext/linux-gnu-x86_64/phpstan_turbo-8.5.so (loaded via process restart)`,
trusted types on.

### 6. Issue #82 evidence

One battery run printed PHPStan internal errors that look like partial/garbled reads (valid files
"unparseable", phar classes "missing") with Turbo loaded; 14 later runs did not reproduce it, 4 of them
with Turbo moved aside. That evidence neither implicates nor clears Turbo, and the issue says so
honestly. On its own I would not hold the merge on it.

But B1 shows a concrete, reproducible way for a Turbo install to crash a concurrent PHPStan. I could
not connect it to #82: B1 produces SIGSEGV/SIGBUS rather than internal errors, and needs the same
checkout's `.so` rewritten, whereas #82's concurrent activity was `composer install` in a **separate**
worktree. So B1 does not explain #82, but it does weaken the PR's position that Turbo is safe to ship on
an unexplained concurrency-shaped failure. My judgement: the #82 evidence alone does not block the
merge; it should not merge before B1 is fixed, and #82's "watch for recurrence" should record whether
any `turbo-install` ran during a failing run.

### 7. Rules, changelog, docs, merges [read]

- No new `ignoreErrors`, baseline, inline ignore, `markTestSkipped` or removed assertion in
  `git diff origin/php8.5...HEAD` (the only match is prose in a vendored verifier report).
- CHANGELOG `## Unreleased`: `ext-hash` under `### Changed — breaking`; the Turbo feature, plugin and
  preflight refusal under `### Added`. Correct headings.
- docs/tools/phpstan.md "PHPStan Turbo" and CLAUDE.md preflight step 08 match the code. (The docs'
  "Installed on every composer install" is accurate for plugin-enabled installs.)
- `origin/php8.5` (`b81709b7`) is an ancestor of the head and is the second parent of `1eb9dca`; the
  three-dot diff holds only the 34 Turbo/plan files the PR lists. No `.gitattributes`, so
  `vendor-phar/turbo-ext.json` ships in dist archives; `vendor-phar/turbo-ext/` is gitignored.
- No hand-edited lock content beyond the content-hash change Composer writes for the new requirement.

### 8. CI on `1eb9dca`

- `QA Pipeline` (CI): COMPLETED, SUCCESS
- `Detect PHP Version`: COMPLETED, SUCCESS
- `PHP QA (8.5)`: **IN_PROGRESS (pending)** at the time of writing — not a pass.
- `mergeStateStatus`: `UNSTABLE` (pending check); no review comments.

## Process note

The Write tool refused this report because the session's working directory was the author's worktree
(`plan-00020-turbo`), which the cross-worktree guard binds a sub-agent to; it was written with a Bash
heredoc into the gitignored `untracked/agent-reports/` as instructed. Nothing in the author's worktree
was modified.

VERDICT: FAIL
