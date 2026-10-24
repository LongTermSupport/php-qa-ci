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
- **35**: the PyPI route in `tools bump` (the wheel's address from PyPI's JSON, its sha256
  cross-checked against PyPI's digest, a yanked file refused).
- **36**: zizmor pinned in the manifest as the sha256-pinned PyPI musl wheel, for both
  architectures.

## In progress: zizmor

The Owner chose, for Task 1.5, the sha256-pinned PyPI musl wheel
([#310](https://github.com/LongTermSupport/php-qa-ci/issues/310#issuecomment-6376453600)). The
slices, in order, each a pull request with a fresh verifier: the PyPI route (merged, 35), the
manifest pin (merged, 36), the lane, then the record's `calibrations` and the zizmor half of
`fix`; the release bundle's zizmor licence is a release-time step.

The lane is [pull request 37](https://github.com/LongTermSupport/actions-qa-ci/pull/37), a draft
that a fresh verifier failed once on five findings, closed or recorded. It runs the pinned
zizmor offline at the regular persona with no configuration, no honoured ignore and no inherited
environment (only `NO_COLOR=1`), and gives each audit the identifier `actionsqaci.zizmor.<audit>`, a rule, a page and
(for those this build runs) a red and a green fixture. Seven audits are held, with the reason in
the listing: the five that need the network (`impostor-commit`, `known-vulnerable-actions`,
`ref-confusion`, `ref-version-mismatch`, `stale-action-refs`), `forbidden-uses` and
`insecure-url-scheme`. Two pages,
`secrets-inherit` and `secrets-outside-env`, wait on the Owner's narrowing of the hooks daemon's
`*.secret*` guard, which refuses an agent the file names; the finished texts are kept in the
session scratch directory.

Two pull requests are stacked on the lane and verified, each to be retargeted to `main` as the one
below it merges. [Pull request 38](https://github.com/LongTermSupport/actions-qa-ci/pull/38) gives a
hazard both tools report one owner where the owner reports everything the other would: actionlint's
`credentials` kind is dropped in favour of zizmor's `hardcoded-container-credentials`, and a rule
states what it absorbs. Its verifier found that the other planned drop, actionlint's potentially
untrusted `expression` messages, loses findings (zizmor's regular persona does not report a
`fromJSON` of an untrusted input), so those stay and the overlap is declared in `dbf.json`.
[Pull request 39](https://github.com/LongTermSupport/actions-qa-ci/pull/39) names, on stderr, each
audit a run does not run and why, for a lane that analysed a file. Still to do after the lane: the
online audits, whose design (§7.4) would run them when a token is set, which is a second network
route beside `check --online`.

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
- Task 2.2, the findings of the lane on this repository's workflows and templates, with options on
  [#310](https://github.com/LongTermSupport/php-qa-ci/issues/310#issuecomment-6380032729): how SHA
  pins are kept current (Dependabot, pinact, or a recorded exception), where
  `persist-credentials: false` is safe (`qa-autofix.yml` pushes with the checkout's credentials),
  and whether `uses: $/…` is supported.
- The overlap on untrusted input and the default persona
  ([#310](https://github.com/LongTermSupport/php-qa-ci/issues/310#issuecomment-6380209499)), which
  is Task 1.6's question. Whether the online audits run on a token or only under `--online` is
  [a separate question](https://github.com/LongTermSupport/php-qa-ci/issues/310#issuecomment-6380421861).
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
