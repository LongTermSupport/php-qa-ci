# Changelog

Changes a consuming project has to know about: anything that alters the public
CLI surface, an exit code, a config contract, or the behaviour of a lane in a way
a green build could notice. Routine internal work is in git history and does not
belong here.

Every change to a path a consumer receives (`src/`, `bin/`, `configDefaults/`,
`templates/`, the shipped PHARs and binaries, `composer.json`, the deployed
`.claude/` assets; the list is in `qaConfig/qa.php`) needs an entry under
`## Unreleased`, and the `changelog` lane fails the build without one. A commit
no consumer could notice says so instead, with the trailer
`Changelog: none — <reason>`.

`## Unreleased` takes these headings and no others, each once, and the release
cut from it is decided by them: `### Changed — breaking`, `### Removed`,
`### Added`, `### Changed` and `### Deprecated` release a new minor version;
`### Fixed` and `### Security` alone release a patch. The major is the PHP line
(`85` for the `php8.5` branch), so a breaking change moves the minor: read the
BREAKING entries before taking one. A green push to the branch opens a release
pull request moving `## Unreleased` into a version section; merging it publishes
the release and its tag. The full rules are in
[docs/tools/changelog.md](docs/tools/changelog.md).

## Unreleased

### Changed — breaking

- **`ext-pcntl` and `ext-posix` are required.** The run handles SIGINT, SIGTERM,
  SIGHUP and SIGQUIT itself and stops its tools' worker processes (see Fixed
  below), which needs both. A host without them is told at `composer install`
  rather than left with runs that cannot clean up after Ctrl-C.

- **An Infection MSI floor of 100 or more is refused, and diff mode no longer
  defaults to 100.** Real code has equivalent mutants no test can kill, so a
  100% floor is only ever met by suppressing mutants or contorting code. The
  configuration now fails to build when `mutationScoreIndicator`,
  `coveredCodeMSI` or `infectionDiffCoveredMsi` (or the matching
  `withInfectionFloors()` / `withInfectionDiffBase()` argument) is 100 or more;
  set 90 to 95 instead. Diff mode, which used to demand 100, now holds changed
  files to the covered-MSI floor unless given its own.

### Added

- **Releases are cut from this changelog.** When CI on a push to `php8.5` is
  green, a release pull request moves `## Unreleased` into a dated
  `85.<minor>.<patch>` section, and is refreshed by every later green push.
  Merging it is the release: CI on the merge publishes a GitHub Release, whose
  tag Packagist reads, with the section as its notes. `bin/changelog-release`
  does the work (`next-version`, `apply`, `notes`, `pending-tags`, `add-entry`,
  `add-tool-updates`) and is available to consuming projects too. See
  [docs/tools/changelog.md](docs/tools/changelog.md).

- **A `changelog` lane (`-t cl`, `phpqaci.changelog`), opt-in.**
  `withChangelogCheck(true)` and `withChangelogWatchedPaths(...)` in
  `qaConfig/qa.php` (or `useChangelogCheck=1`) make the build fail when
  `## Unreleased` is malformed, when a watched path changed without a new entry
  or a `Changelog: none — <reason>` trailer, or when a `composer.json`
  requirement was added or tightened without a `### Changed — breaking` entry.
  It needs the git history (`fetch-depth: 0` in GitHub Actions) and fails
  without it. Off unless enabled, so no consuming project is affected.

- **The hooks daemon keeps the full pipeline with the coordinating session.** Where the
  Claude Code hooks daemon is v3.67.0 or later, composer install/update declares its
  `subagent_full_qa_blocker` in `.claude/hooks-daemon.yaml`: a sub-agent's bare `qa` run
  (or `-p src`, `-p tests`, `-p .`) is denied, single lanes and path-scoped runs are not.
  The edit keeps every comment, leaves a disabled or project-written block alone, and is
  written only after the daemon's `config-validate` accepts it. The deployed guidance
  follows: the coordinating session runs the full pipeline itself, in the background, and
  `php-qa-ci_full-pipeline-runner` now summarises its log instead of running it. See
  [docs/hooks-daemon-full-qa-blocker.md](docs/hooks-daemon-full-qa-blocker.md).

### Changed

- **The GitHub Actions template checks out full history.** The `qa` job in
  `templates/github-actions/php-qa-ci.yml` always uses `fetch-depth: 0`, so
  switching the `changelog` lane on needs no workflow edit. A large repository
  pays a slower checkout.

### Fixed

- **An interrupted run no longer locks out every run after it.** Ctrl-C, `kill`,
  a closed terminal or an agent harness stopping the task used to kill PHP before
  the lock was released, leaving the running tool (and PHPStan's parallel workers)
  orphaned and every following run refused for up to ten minutes. The run now stops
  its running tool and everything that tool started, releases the lock and exits
  128 + the signal number (130 for Ctrl-C, 143 for SIGTERM). The
  lock itself is now an `flock`, which the kernel frees however the holder dies, even
  SIGKILL or the OOM killer, so the ten-minute stale window is gone: a holder that
  is reported is a live one.

## 85.0.0 — 2026-10-02

### Changed — breaking

- **Lock contention exits 75, not 1.** A run that cannot take the run lock has
  checked nothing, so it no longer reports the same code as a failing tool. 75 is
  the conventional `EX_TEMPFAIL`, and the run also prints one line to stderr. A
  caller that treated any non-zero exit as "QA failed" now sees contention as its
  own outcome, and should retry rather than report a defect.

### Added

- **`rule-doc` resolves a consuming project's own rule identifiers.** Declare the
  indexes in `qaConfig/rule-docs.json`. The summary comes from the column headed
  `Forbids`, `Summary`, `Description` or `What`, falling back to the trailing
  cell, so a project ending its rows with provenance needs no reshuffling. The
  shipped index is read first, so a project cannot shadow a `phpqaci.*`
  identifier. See [docs/phpstan-rules/README.md](docs/phpstan-rules/README.md).

### Fixed

- **Per-file PHPStan on a phar tool's config file is actionable.** The wrapper
  neon lists each phar's config-API sources under `scanDirectories`, so
  `qaConfig/phparkitect.php` and `qaConfig/composer-dependency-analyser.php` no
  longer report every class in them as missing.

- **Log retention bounds its directory.** A cap on each tool's archives prunes
  oldest-first, replacing a warning that asked the reader to clear the logs by
  hand. Per-pattern retention alone could not bound the directory, because a
  `-p` run mints a pattern per path analysed.

- **The managed `<phpqaci>` CLAUDE.md block always carries a blank line before
  its closing tag**, so a markdown formatter cannot indent the tag and turn every
  subsequent install into a committed diff.
