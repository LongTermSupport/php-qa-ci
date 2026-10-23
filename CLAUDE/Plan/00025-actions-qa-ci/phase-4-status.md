# Phases 4, 6 to 9: where each slice stands

The state of the actions-qa-ci build after the repository floor, the core, actionlint and the
project record (all in [PLAN.md](PLAN.md)). Pull request numbers are those of
[LongTermSupport/actions-qa-ci](https://github.com/LongTermSupport/actions-qa-ci); the dated detail
is in the [JOURNAL](JOURNAL/).

## Merged

- **24**: the release archive of a platform, built to the same bytes every time from the program,
  the tools the manifest pins and a licence file each (`internal/bundle`, `actions-qa-dev bundle`).
- **25**: `dogfood.yml`, which runs the built program over php-qa-ci at a pinned commit and fails
  on any exit above 1. Left of it is the corpus of public workflows that design §12 also names,
  which needs a choice of repositories and how they are pinned (the Owner's).
- **26**: the `install.sh` template, tested by running it with `sh` against real bundles, and
  `actions-qa-dev install-script`, which fills a release's version and checksums into it.
- **27**: `actions-qa-dev tools bump` (`internal/bump`), which moves a manifest to the latest
  release once it is past the cooldown, for ShellCheck only, which has no attestation upstream
  (the manifest pins it by the sha256 of the release as downloaded). Any other tool is refused
  until its provenance route is built.
- **28**: `update-tools.yml` (design §5.3): daily and on dispatch, it bumps, runs the CI gate when
  something moved, and opens or updates the `chore/update-tools` pull request, or opens or
  comments on an issue labelled `update-tools-failure` when it fails. One dispatched run on `main`
  has succeeded online with nothing to move (the later steps and the pull-request job were
  skipped); the path that moves a tool and opens the pull request has not run, as the bump
  reported every tool up to date. The repository's Actions settings already allow workflows to
  open pull requests, and it opens its pull request with the workflow token, which starts no CI
  run, so the gate runs in its own job first. The three-day cooldown is the default of Task 1.8.
- **29**: the manifest is the only place a pinned tool's version is written (the ShellCheck lane
  had its own constant, and a test and two documents spelled it); a test fails where production
  Go, the README, the documentation, a workflow or a shell template spells a pinned version.
- **30**: `install.sh` no longer leaks a temporary directory when a signal reaches the process
  group between `mktemp` making it and the shell naming it: the directories are named by process
  id before they are made, and a name already taken is refused and never removed. A termination
  test that signalled before `tar` had started is fixed.
- **31**: a tool move is recorded in the changelog (`internal/changelog`, `tools bump --changelog`): the Owner's shape, a `### Changed` entry under `## Unreleased` with no version in
  the `Added` entries, which a test holds. `update-tools.yml` carries the changelog with the
  manifest.
- **32**: the compiled-in actionlint library moves from `update-tools.yml` behind the same cooldown
  (the Owner's shape; `internal/modbump`, `actions-qa-dev modules bump`, then `go get`).
- **33** and **34**: a pinned asset may be a zip, the form a Python wheel is distributed in
  (`internal/toolchain`), with a bound on a zip's size and entry count.

## In progress: zizmor

The Owner chose, for Task 1.5, the sha256-pinned PyPI musl wheel
([#310](https://github.com/LongTermSupport/php-qa-ci/issues/310#issuecomment-6376453600)). The
slices, in order, each a pull request with a fresh verifier: the PyPI route in `tools bump` (the
wheel's address from PyPI's JSON, its sha256 cross-checked against PyPI's digest, a yanked file
refused), then zizmor pinned in the manifest and in the bundle with its licence, then the lane
(findings renamed to `actionsqaci.zizmor.<audit>`, one owner per hazard class, a page and a red
and a green fixture each), then the record's `calibrations` and the zizmor half of `fix`.

## Waiting on the Owner

- pinact's provenance route and the pinact half of `fix`. The tarballs of pinact v5.0.0 have two
  GitHub attestations each, but `gh attestation verify` on this host fails with `verifying with issuer "sigstore.dev"` and no reason, and neither `cosign` nor `slsa-verifier` is installed.
  Pinning by the release's `checksums.txt` alone is weaker than upstream offers, and installing a
  verifier is a new tool, so which to take is the same kind of provenance decision as Task 1.5,
  extended to pinact; the options are on
  [#310](https://github.com/LongTermSupport/php-qa-ci/issues/310#issuecomment-6355208852).
- The record's `calibrations`: `enable` waits on the zizmor lane and on Task 1.6, and
  `shellcheckSeverity` on Task 1.6. `selfHostedRunnerLabels` is spelled in design §8.2, and §8.1
  names actionlint's `self-hosted-runner.labels` a calibration, but it would make
  `actionsqaci.actionlint.runner-label` stop firing with no justification, which the rule's page
  sends to an exception, so adding that second route is a narrowing for the Owner to confirm.
- `release.yml`, which carries a recorded `dangerous-triggers` exception that design §14.9 leaves
  to Task 2.2 and that zizmor reports.
- The CEL engine: the `cel-go` dependency the design chose (D7) is a new dependency, which
  [issue-sdlc.md](../../issue-sdlc.md) leaves to the Owner; posted on #310.
- The corpus for `dogfood.yml`.

## Needs a release, which the agent does not cut

The `install.sh` filled in with a release's checksums and download address (design D3, §3), the
composite action pinned to a release tag (§10.1), `versionPin` (whose useful form needs a release
to pin to), and Task 4.4, which needs a release and the zizmor lane.

## Not built, and why

- `disabledDefence` is in the design and is deliberately not built: a defence turned off
  everywhere is an exception with no path that `rules` shows.
- Design §5.3 step 2, regenerating the upstream identifier sets: the actionlint kinds are
  ratcheted by `TestTheKindsAreTheOnesActionlintCanReport`, the ShellCheck codes share one family,
  and the zizmor audits follow the lane.
- Design §5.3 step 3, a dogfood run inside `update-tools.yml`: `dogfood.yml` runs it on pull
  requests; the corpus waits on the Owner.
