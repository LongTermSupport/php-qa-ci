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
(`85` for the `php8.5` branch, the locked-major policy `qaConfig/qa.php`
declares), so a breaking change moves the minor: read the BREAKING entries
before taking one. A green push to the branch opens a release
pull request moving `## Unreleased` into a version section; merging it publishes
the release and its tag. The full rules are in
[docs/tools/changelog.md](docs/tools/changelog.md).

## Unreleased

### Changed — breaking

- **The Infection lane now fails a run in which no test ran for any mutant counted as killed.** Infection scores a mutant as killed whenever the test process exits non-zero, and the lane skips Infection's initial test run, so a test suite that cannot start under Infection (a PHPUnit extension or bootstrap that fails under Infection's generated config) "killed" every mutant and the lane passed on a vacuous score. The lane now runs Infection from a copy of `infection.json` under `var/qa/infection-config/` that always writes a JSON log (`var/qa/infection/infection-log.json`, or the file your config's `logs.json` already names; a `logs.json` sent to a `php://` stream is replaced by the default file, and other `php://` log targets are kept as written), reads every killed mutant's test output from it, and crashes with `phpqaci.infection` when every one shows that PHPUnit ran no test, naming the mutants and printing the first one's output. When only some do, a kill no test made is still not evidence. Such kills come as readily from a suite that starts only some of the time under Infection as from a mutant that breaks code the test bootstrap runs. So for a passing run the lane works out both scores without them, from the counts in that log. If the floors still hold (the diff floor on a diff run), the run passes and the lane names the kills in a notice. If they do not, the pass rests on those kills, and the lane crashes, naming them and the scores without them ([#125](https://github.com/LongTermSupport/php-qa-ci/issues/125)). A run that passes without writing that log crashes too, as does a log whose stats do not add up to the MSI it reports. If your Infection runs now fail this way, the scores they reported were not measurements: make the suite start under Infection, as `docs/tools/infection.md` describes.

### Changed

- **Bundled tool versions updated** by the dependency update: phpstan/phpstan (in rector.phar) 2.2.16 → 2.3.1; rector/rector 2.6.7 → 2.7.0.

### Fixed

- **A repeated `-p` or `-t` is now refused instead of silently replacing the first.** `qa -t stan -p A -p B` analysed only `B` and could pass, and `-t stan -t rector` ran only rector, in both the `-p A` and `-pA` spellings. The command line has always taken one tool and one path, and two bare paths were already refused, so a run now exits 1 with `Multiple paths not supported: -p A -p B` or `Multiple tools not supported: -t stan -t rector`. A script that passed a path or tool twice was checking less than it named: run the tool once per path. ([#139](https://github.com/LongTermSupport/php-qa-ci/issues/139))
- An interrupted run no longer prints a PHP warning for a child process that exits while the run is stopping it. The process tree read `/proc/<pid>/stat` with a function that warns before it fails, so a process exiting between the check and the read printed `file_get_contents(/proc/…/stat): Failed to open stream`; it is now read without the warning and treated as gone, as before.
- An interrupted run no longer prints a PHP notice, or waits for and signals a child process that has already exited, when that process exits just as its `/proc/<pid>/stat` is being read. The read then printed `Read of 8192 bytes failed with errno=3 No such process` and came back empty, and the empty line was taken for a running process, so stopping the run could keep waiting for a process that had gone, or send `SIGKILL` to its pid, which may by then belong to another process. A read that fails or comes back empty is now treated like a file that cannot be opened: the process is gone if its `/proc` entry has gone, and it is an error otherwise. ([#118](https://github.com/LongTermSupport/php-qa-ci/issues/118))
- **A failed run no longer ends with the `COMPLETED` banner.** The closing banner said `<host> qa <args> COMPLETED` after every run, including one that exited 1, so a reader skimming for the verdict could take a failure for a pass. A run that fails now ends with `<host> qa <args> FAILED (exit N)`, in aggregate and fail-fast mode alike; a passing run still ends with `COMPLETED`. Anything that matched the old banner to detect the end of a run should match both words. ([#112](https://github.com/LongTermSupport/php-qa-ci/issues/112))
- **The PHPStan and dead-code lanes keep PHPStan's cache inside the project.** Neither set PHPStan's `tmpDir`, so both used `/tmp/phpstan`, which every checkout and project on the host shares, and a stale entry written by one checkout failed a clean tree in another. Each lane now sets `tmpDir` in the wrapper neon it generates: `var/qa/cache/phpstan/` for the PHPStan lane and `var/qa/cache/deadCode/` for dead-code detection, kept apart because PHPStan holds one result cache per `tmpDir`. Deleting `var/qa/` resets them. A `tmpDir` your `qaConfig/phpstan.neon`, or a file it includes, already sets is still used, and the lane prints which cache it runs with. The first run after upgrading starts with an empty cache. A CI job that caches `/tmp/phpstan` between runs should cache `var/qa/cache/phpstan/` (and `var/qa/cache/deadCode/`) instead, or set `tmpDir` in `qaConfig/phpstan.neon` or a file it includes; otherwise every CI run starts cold. ([#123](https://github.com/LongTermSupport/php-qa-ci/issues/123))
- **A `tmpDir` set behind an include PHPStan can resolve, but the lanes' own reading of the config could not, is now honoured.** The PHPStan and dead-code lanes follow your `phpstan.neon`'s NEON includes to see whether it sets `tmpDir`, and could not follow an include built from a `%parameter%` (such as `%env.NAME%`) or a PHP config file; a `tmpDir` set there was silently overridden by the lane's `var/qa/cache/<lane>/`. When their own reading finds no `tmpDir`, the lanes now ask PHPStan for the merged value (`phpstan.phar dump-parameters`, which adds a second or two to each run and runs your `bootstrapFiles` once more), keep any value other than PHPStan's default `%sysGetTempDir%/phpstan`, and print the directory. If PHPStan cannot answer, the lane uses its own cache as before. ([#140](https://github.com/LongTermSupport/php-qa-ci/issues/140))

## 85.7.0 — 2026-10-09

### Added

- **A start-up warning when Xdebug is loaded and OPcache JIT is configured on.** PHP prints "JIT is incompatible with third party extensions" on stdout at every start of a process with Xdebug active, which corrupts anything that parses that output. The note says the pipeline is unaffected and that a PHP started by hand needs `XDEBUG_MODE=off`.

### Changed

- **Infection's auto diff mode makes a run full only for the files every mutant depends on, and leaves comment-only changes out.** The full-run triggers are now the resolved Infection config and its `qaConfig/infection.json{,5}{,.dist}` overrides, the resolved PHPUnit config and its `qaConfig/phpunit.xml`, `phpunit.xml.dist` and `phpunit.dist.xml` overrides, and the bootstrap the PHPUnit config names in its `bootstrap` attribute; `composer.json`, `composer.lock` and the rest of `qaConfig/` no longer force a full run, are no longer watched for uncommitted work and are not mentioned. A modified or renamed PHP file whose tokens, comments and whitespace aside, match its merge-base version is not mutated and is named on one "comment-only change, not mutated" line. In a file where either version has a comment containing a directive Infection or the coverage tool reads (`@infection…`, `@codeCoverageIgnore…`, `@deprecated`), every comment counts as code, verbatim and by line, so any comment change mutates it; an added or copied file, or one whose working copy or base cannot be read, is always mutated; a comment-only test change brings nothing into scope.

### Fixed

- **Every child process the pipeline starts now runs with `XDEBUG_MODE=off`, except the coverage runs (PHPUnit with coverage, Infection's coverage generation), which name their own mode.** Before, a child started without a mode of its own (a git call, the markdown formatter, a shell script) inherited whatever the parent had, so with JIT on and Xdebug loaded the "JIT is incompatible" warning reached stdout and broke output parsing.
- The deployed `pre-commit` hook no longer blocks every commit when `php` prints a start-up warning on stdout, such as the OPcache JIT's "JIT is incompatible with third party extensions" when Xdebug is loaded (#100). It reads `composer.lock` with `XDEBUG_MODE=off` and keeps only `name|reference` lines, where a stray line used to stop it with `bad array subscript`. A package off its locked commit is still caught.
- **PHPStan Turbo runs again with PHPStan 2.3.1.** Its phar loads a Turbo binary only with the platform's shared core (`phpstan_turbo_core.so`) beside it, and both live in the `turbo-ext/` of the phpstan/phpstan tag, not in the phpstan/turbo-ext release zips, which hold a single self-contained build the phar does not accept. `bin/turbo-install` now fetches the binary and its core from the tag into `vendor-phar/turbo-ext/<platform>/`, verifies both against `vendor-phar/turbo-ext.json` (whose `assets` object, keyed by release zip name, is now `files`, keyed by path under `turbo-ext/`) and replaces each atomically. Without it PHPStan ran without Turbo and the lane reported "NOT RUNNING". Run `composer update` to refetch.

## 85.6.1 — 2026-10-09

### Fixed

- A `phar://phpstan.phar/...` include in `qaConfig/phpstan.neon` (PHPStan's documented `phar://phpstan.phar/conf/bleedingEdge.neon`) is PHPStan's own configuration, as `%rootDir%/...` is, and is no longer reported as missing: the `phpstanIgnoreJustification` lane passes it, and `rules --write-agent-summary` lists the active defences instead of writing the "could not be generated" fallback into `CLAUDE.md`. Any archive named `phpstan.phar` is treated so; a `phar://` include into any other archive is read like any other file, and fails when the archive cannot be opened.

## 85.6.0 — 2026-10-09

### Changed — breaking

- **BREAKING**: `composer.json` now requires `ext-hash`. Every PHP build since 7.4 includes it, and
  it cannot be disabled, so nothing needs installing. php-qa-ci uses it to check the SHA-256 of each
  PHPStan Turbo binary it downloads.

- **Infection now mutates only what a branch changed, by default.** With `infectionDiffBase` unset, a run on any branch but the default one mutates the PHP files changed since its merge base with the default branch (the target branch in a pull request build), plus the source each changed test is named after; on the default branch, or where no merge base can be found (a shallow clone, an unknown default branch), it mutates everything and prints why. The first Infection line of every run names the scope. Uncommitted work under `src/`, `tests/`, `qaConfig/`, `composer.json` or `composer.lock` (untracked files included) is mutated as it is on disk, and a `WARNING` names each such file, since that verdict is not reproducible from committed history. A branch is now held to the diff floor (`infectionDiffCoveredMsi`, default `coveredCodeMSI`) on its changed files, which can fail where the whole-codebase floor passed. To keep the full run everywhere, set `infectionDiffBase=full` or call `withInfectionFullRun()`. `withInfectionDiffBase(null)` now means this automatic choice rather than a full run. In CI, check out with `fetch-depth: 0` (the shipped templates do), or every run is full. Details: `docs/tools/infection.md`.

- **A diff-mode Infection run, with an explicit `infectionDiffBase` ref as in auto mode, is stricter:**

  - It mutates uncovered code too (`--with-uncovered`) and holds both its MSI and its covered MSI to the diff floor. Before, it only checked the covered MSI, so an untested changed file passed whenever the scope also held a tested one (a scope of untested files alone already failed, at 0%). It now fails either way.
  - A change to `qaConfig/`, `composer.json` or `composer.lock` since the base turns it into a full run, with one line naming the files. Before, those changes were ignored.
  - The clean-tree refusal of an explicit ref now also covers those configuration paths, and names each file inside an untracked directory rather than the directory.
  - A changed test now brings into scope the source file it is named after.

### Added

- **PHPStan runs with Turbo, its native extension.** `bin/turbo-install` downloads the Turbo
  binary built for the host (OS, CPU, libc, PHP version) into `vendor-phar/turbo-ext/`, where
  `phpstan.phar` loads it. It refuses any download whose SHA-256 differs from the one
  `vendor-phar/turbo-ext.json` pins for the shipped phar. In a consuming project the php-qa-ci
  Composer plugin runs it on every `composer install` and `composer update`. A failed download is
  a warning, not a failed install. A host upstream builds nothing for (Windows, an Intel Mac) runs
  PHPStan without Turbo, as before. A run refuses to start when `vendor-phar/turbo-ext.json` is
  missing or names another PHPStan version than the shipped phar, as it does for a missing PHAR.
  With Turbo, PHPStan forks its workers, so the dead-code lane now unpacks its detector into
  `var/qa/cache/dead-code-detector/` instead of loading it as a second PHAR, which forked workers
  cannot share. On this repository the PHPStan lane runs in about half the time (about 20 s to
  9 s) with identical findings, and each worker's peak memory is about 6% higher, so a project
  already close to its memory limit (4G by default, `withMemoryLimit()`) may need a little more.
  The PHPStan lane's text mode says on every run whether Turbo is running, and when it is not on
  a host php-qa-ci ships a build for, prints `NOT RUNNING` with PHPStan's reasons and the fix;
  the run's outcome is unchanged. `vendor-phar/phpstan.phar diagnose` gives the same answer by
  hand.

### Fixed

- **`infectionOnlyCovered=1` no longer makes Infection refuse to run**
  ([#74](https://github.com/LongTermSupport/php-qa-ci/issues/74)). The lane passed
  `--only-covered`, which the bundled Infection 0.35 no longer has: mutating only covered code is
  now its default. The setting is kept and changes nothing. A new test checks every option the
  Infection lane can emit against the shipped `infection.phar`'s own `--help`, so a tool update
  that drops an option fails the build.

- **`qa -t rector -p <path>` no longer runs the PHPUnit Rector set over the whole tests directory**
  ([#76](https://github.com/LongTermSupport/php-qa-ci/issues/76)). The Safe, project and PHP 8.5
  passes were narrowed to the given path, but the PHPUnit pass always got all of `tests/`, so a
  one-file run took about as long as a whole-tree run. On a `-p` run it now gets only the checked
  paths inside the tests directory, the whole directory for a path that contains it (`-p .`), and
  is skipped when there are none. A run without `-p` is unchanged.

- **A PHPStan run that found nothing is no longer reported as findings**
  ([#82](https://github.com/LongTermSupport/php-qa-ci/issues/82)). PHPStan exits 1 for findings,
  but also when it gives up on internal errors ("Result is incomplete because of severe errors"),
  dropping every real finding, and when it stops before analysing anything, on a config error, a
  missing bootstrap file or a rule class it cannot load. The dead-code lane reported all of these
  as "dead code found", with advice to delete members it never named. The PHPStan lane reported
  them as errors found in every mode, without the `--debug` re-run that a crash gets in text mode.
  An exit 1 now counts as findings only when PHPStan reports some, and anything else is a crash.
  The text-mode and dead-code runs pass `--error-format=table`, so a project's `errorFormat` no
  longer changes what they print. `vendor/bin/phpstan-rule` answered "did not fire" for an
  abandoned analysis, including one whose parallel worker died; it now exits 2.

- **Diff-mode Infection mutates renamed and copied source files, under their new path,** where it used to drop them, and reads file names verbatim (`git diff -z`). It now logs at `--log-verbosity=all`, as a full run already did, so `log.txt` lists every escaped mutant. A scope whose changed files hold no mutable code at all (an interface, constants) now passes instead of scoring 0%.

## 85.5.0 — 2026-10-08

### Changed

- **Bundled tool versions updated** by the dependency update: phpstan 2.2.16 → 2.3.0.

### Fixed

- **A `composer update` no longer installs PHPStan extensions the shipped phar cannot load**
  ([#60](https://github.com/LongTermSupport/php-qa-ci/issues/60)). `composer.json` replaced
  `phpstan/phpstan` with `*`, so Composer treated every PHPStan version as present and installed
  the newest extensions: `phpstan/phpstan-strict-rules` 2.1.0 and `phpstan/phpstan-phpunit` 2.1.x
  require PHPStan ^2.3, and the `phpstan` lane then aborted with "Running PHPStan with incompatible
  extensions" against an older phar. The replace is now the phar's exact version (2.3.0), so
  Composer resolves only extension releases the phar can load. `bin/phpstan-replace-sync` verifies
  it on every install and moves it with the phar on a maintainer update.
- **`bin/changelog-release add-tool-updates` records a dependency that moved inside a self-built PHAR**, such as a package Rector bundles, as `<package> (in <tool>.phar)`. Before, only the tool itself was compared, so an update that changed such a PHAR recorded nothing and its pull request failed the `changelog` lane.

## 85.4.0 — 2026-10-07

### Changed — breaking

- **BREAKING**: the `phpArkitect` lane fails, without running arkitect, when the project's own
  `qaConfig/phparkitect.php` builds its class set by hand while a `withIgnoredPaths()` entry is
  under the source directory, since those classes would still be checked. Build the class set
  from the shipped factory instead, `(require getenv('PHPQACI_ARKITECT_CLASS_SET'))($srcDir)`, as
  `templates/qaConfig-phparkitect.php` now does; it applies `Generated` and
  `withArkitectExcludedPaths()` as before.
- **BREAKING**: the `analysedPaths` lane fails a `withIgnoredPaths()` entry that names a path
  where nothing exists, as it already failed a `withUnanalysedPath()` that excuses nothing: the
  exclusion outlived what it excluded, and would hide whatever is added there later from every
  scanning lane. Remove the entry. An ignored directory a build step generates must exist before
  the pipeline runs.
- **BREAKING**: the defect record is removed. `qaConfig/defect-record.neon` is no longer read,
  `vendor/bin/rules` no longer prints a defect-record section or the `defectRecord` JSON key, the
  active-defences region `rules --write-agent-summary` writes has no deferred-defects section, and
  the `phpstanIgnoreJustification` lane checks only the `ignoreErrors` justifications. A project
  that kept a record should fix each entry, or file it as an issue on the upstream project where
  the code is not its own, then delete the file.
- **BREAKING**: `withTypeCoverageFloors()` has no `declare` argument. type-coverage 2.4 stopped
  measuring the share of files declaring `strict_types`, and printed a deprecation on stderr while
  the floor went unenforced. Remove `declare:` from the call; the `phpStrictTypes` lane already
  requires the declaration in every file.

### Added

- A `markdownFormat` lane (`vendor/bin/qa -t mdf`) in the coding-standards phase keeps the
  project's markdown in the form the Claude Code hooks daemon rewrites it to. It runs the daemon's
  own `format-markdown`, so the result matches the daemon byte for byte, and skips where no daemon
  is installed (CI included). A read-only run fails on files the daemon would rewrite; a writable
  run rewrites them. It covers `README.md`, `CLAUDE.md`, `CHANGELOG.md`, `docs/` and `CLAUDE/`, or
  the list given to `withMarkdownFormatPaths()` in `qaConfig/qa.php`; a listed path that is absent
  or gitignored is skipped with a line naming it. See `docs/tools/markdownFormat.md`.
- `vendor/bin/rule-doc` resolves PHPStan's own identifiers, and those of the extensions php-qa-ci
  installs, offline, the way it resolves its own: `rule-doc argument.type` prints PHPStan's page
  for it (code example, why it is reported, how to fix it) rather than a phpstan.org URL. The pages
  are PHPStan's, carried in `vendor-docs/phpstan/` and refreshed with the shipped phpstan.phar by
  `bin/phpstan-docs-install` during a maintainer `composer update`. The type-coverage extension
  publishes no pages, so `docs/phpstan-extension-rules/` holds one for each of its identifiers. An
  identifier none of these carry still names its phpstan.org page.
- Every rule of the bundled PHPArkitect tiers carries an identifier, ending its `because` clause
  (`[phpqaci.interfaceSuffix]`), so a `phpArkitect` violation names the rule that fired.
  `vendor/bin/rule-doc <identifier>` prints its page (`docs/arkitect-rules/`) and
  `vendor/bin/arkitect-rule <identifier> <path>` runs that one rule on one path. A consumer
  matching arkitect output on the old clause text still matches: the identifier is appended.

### Fixed

- The `phpArkitect` lane honours `withIgnoredPaths()`. The shipped class-set factory
  (`configDefaults/generic/phparkitect-class-set.php`, exported as `PHPQACI_ARKITECT_CLASS_SET`)
  drops each ignored path anchored at the source directory, so ignoring `src/Legacy` no longer
  leaves `src/Legacy/` checked, and does not drop `src/Domain/Legacy/` either.
- The `infection` lane honours `withIgnoredPaths()`. When an ignored path lies under one of
  `infection.json`'s source directories, the lane runs Infection with a derived copy of the
  config at `var/qa/infection-config/infection.json`: its paths made absolute and the ignored
  path added to `source.excludes`, anchored at its source directory. Diff mode no longer mutates
  a changed file under an ignored path, and a run whose every source directory is ignored is
  skipped.
- `rules --write-agent-summary` writes the active-defences region in the markdown formatter's
  canonical form: a blank line inside each marker, and a backslash in a summary escaped where it
  is text (a code span keeps it as written). A formatter run over the document, as an editor or
  the hooks daemon does after every edit, no longer rewrites the region and leaves it reported as
  out of date. The `sensitiveParameterUsage` summary puts `#[\SensitiveParameter]` in a code span.

## 85.3.0 — 2026-10-03

### Changed — breaking

- **PHP outside the checked paths fails the run until it is classified.** The
  new always-on `analysedPaths` lane (`-t ap`) lists the project's PHP through
  git and names each directory that is neither under a checked path nor
  declared in `qaConfig/qa.php`; `vendor/` and `var/` are excluded by default.
  Analyse code the project maintains with `withCheckedPaths('config')`, or
  declare the exception with `withUnanalysedPath('<path>', '<reason>')`, whose
  reason is required and printed on every run. See
  [docs/tools/analysedPaths.md](docs/tools/analysedPaths.md).
- **`bin/changelog-release` and the `changelog` lane follow semantic versioning
  unless the project declares otherwise.** A `### Changed — breaking` or
  `### Removed` entry now releases the next major (the next minor while the
  major is 0), and every plain `X.Y.Z` tag counts as a release, so the lane on
  the default branch measures from the newest one. A project that relied on the
  major being its PHP line declares
  `withReleaseVersionPolicy(ReleaseVersionPolicy::lockedMajorFromPhpRequirement())`
  in `qaConfig/qa.php` and keeps today's versions. `pending-tags` prints the tag
  name, prefix included, rather than the version. `ReleaseVersionCalculator` is
  replaced by `ReleaseVersionPolicy` and `ReleaseLine`, `ReleaseBumpEnum` gains
  `Major`, and `ReleasedSections::untagged()` takes a `ReleaseLine`. See
  [docs/tools/changelog.md](docs/tools/changelog.md#versioning-policies).

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

- **Releases follow a versioning policy declared in `qaConfig/qa.php`.**
  `withReleaseVersionPolicy()` takes
  `ReleaseVersionPolicy::semanticVersioning()`, the default, whose first release
  is `0.1.0` unless given another; `lockedMajor(<int>)`, which never moves the
  major; or `lockedMajorFromPhpRequirement()`, the major being composer.json's
  PHP line (`^8.5` is `85`). Each takes a tag prefix such as `v`; a tag of any
  other shape is not a release. `notes` accepts the tag as well as the version.

- **A release workflow ships for consuming projects.**
  `templates/github-actions/release.yml` and
  `templates/github-actions/approve-held-ci/action.yml`, copied unchanged into
  `.github/`, give a project the release pull request php-qa-ci releases itself
  with: a green push to the default branch opens or refreshes
  `chore/release-<branch>`, and merging it publishes the GitHub Release and its
  tag. The branch comes from the repository, the CLI from
  `composer config bin-dir`, the version from the project's release policy.
  Setup and the repository settings it needs are in
  [docs/github-actions.md](docs/github-actions.md#release-automation).

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
- **`withIgnoredPaths()` reaches every lane that scans the checked paths.**
  PHPStan, Dead Code Detection, PHP CS Fixer, PHP Strict Types, PHPCPD and
  PSR-4 Validation scanned the ignored paths anyway, so a project following the
  docs had its fixtures' deliberate violations reported and had to repeat each
  path in every tool's own config. PHPStan and Dead Code Detection now exclude
  them from the report (`excludePaths.analyse`, so their classes stay
  discoverable), PHP CS Fixer filters them out of whatever finder the project's
  config uses, and the rest skip them; a `-p` PHPStan run inside an ignored
  path is skipped. An exclusion repeated in a project's own `phpstan.neon`,
  `php_cs_finder.php` or `psr4-validate-ignore-list.txt` can be dropped.
  PHPArkitect and Infection still need their own setting.
- **Rector no longer warns "This skipped rule is never registered" on every
  run.** The shipped `rector-php85.php` skipped
  `NullToStrictStringFuncCallArgRector`, which Rector 2.6.7 registers in no set,
  so the skip did nothing except print a warning naming a file the project does
  not own. The skip is gone; the rule stays out because no set registers it.

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
