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

- **PHP outside the checked paths fails the run until it is classified.** The
  new always-on `analysedPaths` lane (`-t ap`) lists the project's PHP through
  git and names each directory that is neither under a checked path nor
  declared in `qaConfig/qa.php`; `vendor/` and `var/` are excluded by default.
  Analyse code the project maintains with `withCheckedPaths('config')`, or
  declare the exception with `withUnanalysedPath('<path>', '<reason>')`, whose
  reason is required and printed on every run. See
  [docs/tools/analysedPaths.md](docs/tools/analysedPaths.md).

### Added

- **`vendor/bin/arkitect-rule <because> <path>` proves a PHPArkitect rule
  fires.** It runs the project's own rules (its resolved entry config, tiers and
  environment, no baseline) over one fixture file or directory instead of their
  class sets, and reports whether the rule with that `because` clause fired: exit
  1 with each class, 0 when it did not, 2 when there is no verdict. A rule with
  no instance in `src/` can now be seen to fire before a green arch run is
  trusted. See
  [docs/tools/phpArkitect.md](docs/tools/phpArkitect.md#proving-a-rule-fires).
- **A defect record: `qaConfig/defect-record.neon`.** Method specification
  1.1.0 requires a defect found and not fixed now, and the conclusion that no
  pattern exists, to be recorded where the project's decisions are enumerable.
  Write them under `deferred` (defect, class where apparent, found, deferredBy)
  and `noPattern` (defect, found, conclusion, two or more techniques).
  `vendor/bin/rules` lists the record in text and in JSON (`defectRecord`), the
  active-defences region of `CLAUDE.md` carries it, and the
  `phpstanIgnoreJustification` lane fails on an entry it cannot read, such as a
  misspelt field. A project without the file passes as before; its `CLAUDE.md`
  region gains a line saying where a deferred defect goes. Format:
  `vendor/bin/rule-doc phpqaci.phpstanIgnoreJustification`.

### Changed

- **Bundled tool versions updated** by the weekly dependency update: shipmonk/dead-code-detector 1.4.1 → 1.4.2; phpcpd-next/phpcpd v1.4 → v2.0; rector/rector 2.6.6 → 2.6.7.
- **The Defence Before Fix declaration moves to method specification 1.1.0.**
  `composer.json` `extra.defence-before-fix` states method 1.1.0 at both the
  artefact and the project level, with no new known gap.

### Fixed

- **A diff-scoped Infection run with nothing to mutate no longer generates
  coverage first.** With `infectionDiffBase` set, the lane resolves the
  changed-file list before any coverage run, so a docs-only or config-only
  change skips at once instead of spending a full Xdebug PHPUnit run and then
  reporting nothing to mutate. When there are changed source files, coverage
  is reused or generated exactly as before.
- **The GitHub Actions templates run on Node 24.** `templates/github-actions/`
  pinned `actions/checkout`, `actions/cache`, `actions/upload-artifact` and
  `actions/download-artifact` at v4 and `marocchino/sticky-pull-request-comment`
  at v2, all on the deprecated Node 20 runtime, so every run printed a
  deprecation annotation and the steps stop running once GitHub removes Node 20.
  They are now v7, v6, v7, v8 and v3. `actions/download-artifact@v8` fails on a
  digest mismatch where v4 only warned. A project that copied the templates
  should copy them again.

> > > > > > > agent-a234aa19507b7e520-7ef8a3e5

## 85.2.0 — 2026-10-03

### Changed — breaking

- **Suppressions reached through `includes:` must be justified too.** The
  `phpstanIgnoreJustification` lane reads `qaConfig/phpstan.neon` and every NEON
  file it includes, so an `ignoreErrors` entry in an included file, including a
  generated baseline, needs the same comment above it. It also fails on an entry
  written inline (`ignoreErrors: [...]`), an include that does not exist or uses
  a `%parameter%` other than `%currentWorkingDirectory%`, and a `.php` include
  that sets `ignoreErrors`. See
  [docs/tools/phpstan.md](docs/tools/phpstan.md#suppressing-errors).

- **The PHPArkitect lane never reads `phparkitect-baseline.json`.** It runs with
  `--skip-baseline`, so violations a baseline listed are reported, and it names
  the file when present. Fix them, or declare the exception with
  `withArkitectExcludedPaths()` in `qaConfig/qa.php`.

- **An inline PHPStan ignore must give its reason.** `rules-default.neon` turns
  on `reportIgnoresWithoutComments`, behind the tier's ban on inline ignores:
  where a project has excluded that rule, an inline ignore must name an
  identifier and give a reason in parentheses.

### Added

- **The `CLAUDE.md` block lists the defences active in the project.** Composer
  install/update writes one line per active defence into the `<phpqaci>` block,
  with its identifier and page, generated from the project's own configuration
  by `vendor/bin/rules --write-agent-summary`; `--agent-summary` prints it.
  Rules without an identifier are gathered into one line per package, so the
  section stays short.

- **The defaults php-qa-ci assumes for what Defence Before Fix leaves to the
  project.** `docs/defence-before-fix-defaults.md` states the Owner, where the
  project record is, the sweep scope, where fixtures go, and the hazard, search
  and class-breadth calibrations a project has not recorded, and the `CLAUDE.md`
  block points an agent at it. Toolchain 6.4 is no longer a known gap in
  `extra.defence-before-fix`.

### Fixed

- **The GitHub Actions templates work with any bin-dir and fetch nothing.**
  `php-qa-ci.yml` and `qa-autofix.yml` run `"$(composer config bin-dir)/qa"`
  instead of `vendor/bin/qa`, so a project that sets `config.bin-dir` needs no
  edit. `php-qa-ci.yml` no longer installs PHIVE and fetches PHARs into
  `vendor/lts/php-qa-ci` (the package ships them) or caches `vendor-phar/`,
  which could restore older PHARs over the installed ones; it triggers on the
  `bugfix/`, `chore/` and `hotfix/` branches the branch policy allows; and
  `AUTO_COMMIT_FIXES: true` now runs the QA step writable, so it has fixes to
  commit. Copy the templates again to pick this up.

- **`bin/rules` no longer hangs on a cyclic include** or fails on PHPStan's own
  `%rootDir%` configuration: it walks `includes:` the way the justification lane
  does, and names an include it cannot follow.

- **`bin/rules` lists the rules `phpstan/extension-installer` delivers.** In a
  consuming project the bundled tiers arrive that way, so the listing showed
  none of them; it now reads the installer's configuration, names the package
  each rule came from, and counts rules registered behind a parameter
  (`conditionalTags`), as `phpstan-strict-rules` registers all of its.

- **Every rule and lane page states the correct construction.** The PHPStan,
  PHPUnit, Infection, SensitiveParameter-usage, branch-name, Twig Lint and Yaml
  Lint pages now say what to write when the lane fails, and the release guard
  fails a page whose fix section is missing or only restates the summary, so
  toolchain 8.1 is no longer a known gap in `extra.defence-before-fix`.

- **The default `phpunit.xml` validates against PHPUnit 13.4.** It set
  `executionOrder="depends,random"`, which PHPUnit 13.4 no longer accepts, so
  every run printed two test runner deprecations and PHPUnit 14 would stop
  running it. It now sets `executionOrder="random"`; dependencies are still
  resolved, which is PHPUnit's default. The schema URL and
  `SYMFONY_PHPUNIT_VERSION` move to 13.4. A project with its own copy of the
  file makes the same change.

## 85.1.0 — 2026-10-03

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

- **An interrupted run waits for the workers it kills.** A tool worker that
  ignores SIGTERM is sent SIGKILL after the grace period, and the run now waits
  (up to two seconds) for it to be gone before releasing the lock, so the next
  run cannot start beside a worker that is still exiting.

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
