# Changelog

Changes a consuming project has to know about: anything that alters the public
CLI surface, an exit code, a config contract, or the behaviour of a lane in a way
a green build could notice. Routine internal work is in git history and does not
belong here.

## Unreleased

### Changed — breaking

- **Lock contention exits 75, not 1.** A run that cannot take the run lock has
  checked nothing, so it no longer reports the same code as a failing tool. 75 is
  the conventional `EX_TEMPFAIL`, and the run also prints one line to stderr. A
  caller that treated any non-zero exit as "QA failed" now sees contention as its
  own outcome, and should retry rather than report a defect.

### Added

- **The hooks daemon keeps the full pipeline with the coordinating session.** Where the
  Claude Code hooks daemon is v3.67.0 or later, composer install/update declares its
  `subagent_full_qa_blocker` in `.claude/hooks-daemon.yaml`: a sub-agent's bare `qa` run
  (or `-p src`, `-p tests`, `-p .`) is denied, single lanes and path-scoped runs are not.
  The edit keeps every comment, leaves a disabled or project-written block alone, and is
  written only after the daemon's `config-validate` accepts it. The deployed guidance
  follows: the coordinating session runs the full pipeline itself, in the background, and
  `php-qa-ci_full-pipeline-runner` now summarises its log instead of running it. See
  [docs/hooks-daemon-full-qa-blocker.md](docs/hooks-daemon-full-qa-blocker.md).

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
