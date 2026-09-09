# Plan 00006: PHAR-only tool delivery

**Status**: In Progress
**Created**: 2026-09-09
**Owner**: Joseph Edmonds
**Priority**: High

## Overview

php-qa-ci's delivery rule is that a tool arrives as a self-contained PHAR under `vendor-phar/`,
so a consumer's dependency graph carries none of the tool's own dependencies and no two tools
can ever conflict. Seven tools already follow it (phpstan, rector, php-cs-fixer, infection,
phparkitect, composer-require-checker, twig-cs-fixer); Rector shows the rule holds even when
upstream ships no PHAR, because we build one ourselves with Box.

Four CLI tools still arrive through `composer.json` `require`: `php-parallel-lint`,
`phpcpd-next/phpcpd`, `shipmonk/composer-dependency-analyser` and the `ergebnis/composer-normalize`
Composer plugin. Each is dependency-free today, so the cost is small now, but the exception is the
defect: every consumer carries four packages and must allow-list a Composer plugin, for tools
the pipeline only ever runs as subprocesses. This plan moves all four to PHARs and leaves the
`require` section holding only what genuinely must be composer-installed.

**Start here**: nothing has been built yet. Phase 1 is the two tools with official PHARs; Phase 2
generalises the Rector build for the two without one. The Owner's ruling on the mega-PHAR
question is recorded under Non-Goals.

## Goals

- Every tool a lane runs as a subprocess is a PHAR under `vendor-phar/`, listed in `phive.xml`
  (official) or built by a script under `scripts/` from a manifest under `build/` (self-built).
- `composer.json` `require` holds no CLI tool package: after this plan it lists PHP extensions,
  `composer-plugin-api`, the libraries php-qa-ci's own PHP code uses, PHPUnit, and the PHPStan
  extensions the PHAR loads through the extension installer.
- A consumer no longer needs `allow-plugins: ergebnis/composer-normalize`; the composerChecks
  lane runs the composer-normalize PHAR directly.
- The full pipeline is green here and in both consumers on the result.

## Non-Goals

- **One mega-PHAR bundling every tool.** Owner's question, answered no: each upstream PHAR is
  php-scoper-prefixed and built from a dependency graph that conflicts with the others (five
  different pinned Symfony sets among them), so a single build means resolving one graph across
  all of them, which is exactly the problem PHARs exist to avoid. Nested `phar://` paths are
  unopenable (the Rector build had to extract its bundled phpstan for that reason), so the only
  route is extracting every tool into one tree and a dispatcher, at hundreds of megabytes that
  git re-stores in full on every single-tool bump. Per-tool PHARs keep each upgrade a one-file
  change and keep PHIVE's signature verification for the official ones.
- **PHPUnit as a PHAR.** Consumers write code against PHPUnit's classes: tests extend `TestCase`,
  PHPStan analyses them with the phpunit extension, IDEs index them. Those need the classes
  autoloadable in the consumer, which a PHAR does not give. PHPUnit stays a composer package.
- **PHPStan extensions as PHARs.** They are loaded into the phpstan process through the
  extension installer from the consumer's autoload; there is no PHAR form. The alternative, a
  self-built phpstan.phar bundling the extensions (the Rector pattern), trades a PHIVE fetch for
  a custom build on every PHPStan release; noted for the Owner, not done here.

## Tasks

### Phase 1: official PHARs

- [x] ✅ **Task 1.1**: `composer-normalize` via PHIVE (alias `composer-normalize`, GPG-signed
  releases). Add to `phive.xml`, fetch with `scripts/tool-install.bash update`, commit the PHAR.
  `ComposerChecksTool` runs `vendor-phar/composer-normalize.phar` (`--dry-run` in a read-only run)
  instead of `composer normalize`, and drops the allow-plugins precondition. Remove
  `ergebnis/composer-normalize` from `require` and from the dependency-analyser ignore list.
  Docs: `docs/tools/composerChecks.md`, README and CLAUDE.md allow-plugins sections, the
  upgrading guide.
- [x] ✅ **Task 1.2**: `parallel-lint` via PHIVE from its GitHub release asset
  (`php-parallel-lint/php-parallel-lint`, unsigned, so `--force-accept-unsigned` in
  `scripts/tool-install.bash`; PHIVE records the version in `phive.xml`). `PhpLintTool` runs
  `vendor-phar/parallel-lint.phar`. Remove both `php-parallel-lint/*` packages from `require`
  and the analyser ignores.
- [x] ✅ **Task 1.3**: The update path really updates. `scripts/tool-install.bash update` drops
  the `installed=` pins and re-resolves every constraint from a fresh PHIVE home with
  `--trust-gpg-keys` and `--force-accept-unsigned`, non-interactively; `update-deps.yml` calls it
  instead of the invalid `phive update --copy --trust-gpg-keys` step. Proven by a real run that
  re-resolved all eight tools to identical bytes.

### Phase 2: self-built PHARs

- [x] ✅ **Task 2.1**: Generalise `scripts/build-rector-phar.bash` into `scripts/build-phar.bash <tool>|--all`, driven by `build/<tool>/composer.json` + `box.json.dist` with an optional
  `prepare.bash` hook; Rector's phpstan extraction is `build/rector/prepare.bash`. Same Box
  location rules, same umask fix, same "build if missing or manifest changed" behaviour.
  `build-rector-phar.bash` stays as a forwarding entry point.
- [x] ✅ **Task 2.2**: `phpcpd` from `phpcpd-next/phpcpd` (no release PHAR; zero dependencies).
  `PhpcpdTool` runs `vendor-phar/phpcpd.phar`; package leaves `require` and the analyser ignores.
- [x] ✅ **Task 2.3**: `composer-dependency-analyser` from `shipmonk/composer-dependency-analyser`
  (no release PHAR; zero dependencies). `ComposerDependencyAnalyserTool` runs the PHAR; the
  `bin/composer-dependency-analyser` stub and the package go. `PharToolsVerifier` requires one
  `vendor-phar/<tool>.phar` per `build/<tool>/` manifest.

### Phase 3: enforcement and docs

- [x] ✅ **Task 3.1**: A guard that the exception cannot creep back: PHPStan rule
  `ForbidBinDirToolRule` (`phpqaci.binDirTool`) reports any path built from
  `ProjectPathsDto::$binDir` other than phpunit/paratest, naming the PHAR route. The composer
  side is already netted: the dependency analyser reports a CLI-only package in `require` as
  unused now that the ignores are gone. Defence Before Fix shape: proven red on the pre-plan
  `PhpLintTool` and `PhpcpdTool`, green on the current tree.
- [x] ✅ **Task 3.2**: Docs sweep: `docs/phpqa-tools.md`, `docs/pipeline.md`, per-tool pages,
  CLAUDE.md's tool reference, `CLAUDE/prepush-verification.md` if the maintainer build path
  changes, and the GitHub Actions workflow that rebuilds PHARs (`update-deps.yml`).
- [ ] ⬜ **Task 3.3**: Move both consumers to the result; accounts-api and accountsiq drop the
  composer-normalize allow-plugins entry; full pipeline green in each.

## Success Criteria

- [x] `jq '.require | keys' composer.json` lists no CLI tool package.
- [x] `vendor-phar/` holds composer-normalize, parallel-lint, phpcpd and
  composer-dependency-analyser PHARs, each with a recorded version and provenance (`phive.xml`
  for the first two, `build/<tool>/composer.lock` for the others).
- [x] A consumer with no `allow-plugins` entry for composer-normalize passes composerChecks
  (`QaEntrypointTest`'s fixture consumer has none).
- [x] Task 3.1's guard is red on the pre-plan lanes and green after.
- [ ] Full unfiltered pipeline exit 0 here and in both consumers.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00006-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Plan filed after the Owner's ruling that tools ship as PHARs, built by us when upstream ships none.
