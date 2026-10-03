# Plan 00015: release automation for consumers

**Status**: In Progress
**Created**: 2026-10-03
**Owner**: joseph
**Priority**: Medium

## Overview

Plan 00014 made `CHANGELOG.md` the only input to a php-qa-ci release: the `changelog` lane fails
an unrecorded consumer-facing change, a green push opens a release pull request, and merging it
publishes the tag and the GitHub Release. Everything except the version rule and the workflow's
location is already generic, so a consuming project should be able to adopt the same process by
copying a template and choosing a versioning policy.

The version rule is the one php-qa-ci-specific part. Here the major is locked to the PHP line the
branch targets (`^8.5` is `85`), so a breaking change moves the minor. That is the exception:
standard semantic versioning moves the major on a breaking change. The shipped default must
therefore be semantic versioning, with the locked major as an explicit override that this
repository declares for itself.

## Goals

- A consuming project gets changelog-driven releases from a shipped GitHub Actions template plus
  its existing `changelog` lane, with no php-qa-ci-specific edits.
- The default versioning is semantic versioning: a breaking change or removal moves the major
  (the minor while the major is 0), an addition or change moves the minor, a fix or security fix
  moves the patch.
- A project overrides the policy in `qaConfig/qa.php`, typed; php-qa-ci declares its locked
  major there, and its own releases are unchanged.
- This repository's `release.yml` is the shipped template, held identical by a test, so the
  template is proven by every php-qa-ci release.

## Non-Goals

- Publishing anywhere other than a git tag and a GitHub Release (Packagist reads the tag).
- Choosing versions by hand, or tagging outside the release pull request.
- Supporting a CHANGELOG format other than the one the `changelog` lane already validates.

## Tasks

### Phase 1: A versioning policy

- [x] ✅ **Task 1.1**: Red first: tests for the semantic-versioning policy (each heading's bump,
  the 0.x rule, the first release with no tag, a tag prefix such as `v`, tags of other shapes
  ignored) and for the locked-major policy (today's behaviour, major given explicitly or from the
  PHP requirement).
- [x] ✅ **Task 1.2**: Generalise `ReleaseVersionCalculator` and `ReleaseBumpEnum` behind the
  policy; `bin/changelog-release` (`next-version`, `apply`, `pending-tags`, `notes`) reads the
  policy from the project's configuration.
- [x] ✅ **Task 1.3**: The policy is a typed `with*()` setting on `QaConfigBuilder`, default
  semantic versioning; php-qa-ci's `qaConfig/qa.php` declares the locked major from the PHP line.

### Phase 2: The shipped workflow

- [x] ✅ **Task 2.1**: `templates/github-actions/release.yml`: the default branch and the release
  branch from the repository rather than `php8.5`, the bin dir from `composer config bin-dir`,
  the held-CI approval included.
- [x] ✅ **Task 2.2**: Ship the approve-held-ci composite action as a template alongside it.
- [x] ✅ **Task 2.3**: This repository's `.github/workflows/release.yml` and its action become
  copies of the templates, with a test failing on any drift.

### Phase 3: Documentation

- [x] ✅ **Task 3.1**: `docs/github-actions.md` and `docs/tools/changelog.md`: adopting the
  release workflow, the repository settings it needs, the two policies and the override.
- [x] ✅ **Task 3.2**: `CLAUDE/releases.md` names the locked-major override as this repository's
  choice and points at the shipped mechanism.

### Phase 4: Prove it

- [x] ✅ **Task 4.1**: A Large test drives the release flow end to end in a scratch repository
  under both policies.
- [ ] ⬜ **Task 4.2**: The next php-qa-ci release goes through the template copy with the
  override and produces the version the locked-major policy names.

## Success Criteria

- [x] A project with no versioning setting releases `2.0.0` after `1.4.2` when `## Unreleased`
  carries a `Changed — breaking` entry, and `1.5.0` when it carries only `Added`.
- [x] php-qa-ci releases `85.N+1.0` for a breaking change, as today.
- [x] `.github/workflows/release.yml` and the template cannot drift without a test failing.
- [ ] The full battery passes.

## Technical Decisions

### Decision 1: The policy lives in `qaConfig/qa.php`

**Context**: the release CLI runs outside the pipeline, so `composer.json` `extra` was the other
candidate. **Decision**: `qaConfig/qa.php`, through a typed builder method, because every other
php-qa-ci setting is there, a misspelt override fails at load time, and the `changelog` lane's
watched paths are already configured there.

### Decision 2: Semantic versioning is the default and the locked major is the override

**Context**: php-qa-ci's own scheme is the one in use today. **Decision**: the default follows the
convention a consumer expects, so adopting the workflow never surprises them; php-qa-ci opts into
the exception explicitly, in its own configuration.

### Decision 3: The first semantic release is `0.1.0`, configurable

**Context**: a project with no release tag needs a first version. **Decision**: `0.1.0`, the
start semver.org suggests for initial development, overridable with
`ReleaseVersionPolicy::semanticVersioning(firstVersion: '1.0.0')`. A locked major starts at
`<major>.0.0`, as before.

### Decision 4: The release starts on the QA workflow php-qa-ci's templates name

**Context**: a `workflow_run` trigger lists workflow names and cannot take an expression, and a
consumer's QA workflow is named after whichever template it copied. **Decision**: `workflows:`
lists `CI`, `PHP QA Pipeline` and `QA`, and the job runs only for a green push to the
repository's default branch, so the template copies unchanged; a project with another name, or
more than one of them on that push, edits that one line.

## Delivery & Milestones

<!-- Curated milestones + delivery commit hashes only (git is the SSoT for
     "when" — do not add dates). The blow-by-blow activity log lives in
     JOURNAL/00015-Journal-YY-MM-DD.md — see CLAUDE/PlanJournalling.md. -->

- Plan filed
- Phases 1–3 and Task 4.1: `b0ec234` (red tests), `140e1ec` (the versioning policy), `06b271f`
  (the workflow templates and documentation)
