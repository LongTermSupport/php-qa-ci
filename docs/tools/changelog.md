# Changelog

**Identifier**: `phpqaci.changelog`

An opt-in check that `CHANGELOG.md`'s `## Unreleased` section can be read without guessing, and
that every change a consuming project could notice is recorded there before it merges. The same
section is what [`bin/changelog-release`](#releasing-from-the-section) reads to decide the next
version and write the release notes, so a section the check accepts is one a release can be cut
from.

## The section's construction

`## Unreleased` appears exactly once and holds only `###` headings from this list, each at most
once and each with at least one entry:

| Heading                  | What belongs there                                                         | Next release |
| ------------------------ | -------------------------------------------------------------------------- | ------------ |
| `### Changed — breaking` | a change a consuming project must act on: a new requirement, a new failure | minor        |
| `### Removed`            | a lane, option, command or file a consumer could have relied on is gone    | minor        |
| `### Added`              | a new lane, option, command or rule                                        | minor        |
| `### Changed`            | a behaviour change a consumer can notice but need not act on               | minor        |
| `### Deprecated`         | something that still works and will be removed                             | minor        |
| `### Fixed`              | a defect corrected                                                         | patch        |
| `### Security`           | a vulnerability corrected                                                  | patch        |

The major version is not on the list: it is the PHP line the branch targets, so a breaking change
moves the minor. A section with no entries means no release is due.

An entry is a `- ` list item; a line that continues it is indented. Anything else is refused
rather than interpreted: a heading not in the table (`### Improved`, `#### Detail`), a heading
used twice, a heading with no entries, text between `## Unreleased` and its first heading, and
unindented text under a heading. Every problem is reported at once, with its line number.

```markdown
## Unreleased

### Changed — breaking

- **`ext-pcntl` is required.** The run stops its child tools on SIGTERM, which needs it.

### Fixed

- **An interrupted run releases the lock.** Ctrl-C no longer locks out the next run.
```

The section ends at the next `##` heading. Released sections below it are not checked.

## What it checks

1. **The section is valid**, as above.

2. **Every watched change is recorded.** The lane works out the changes being judged:

   - on a branch, or in a pull request build (`GITHUB_BASE_REF`), everything since the merge base
     of `HEAD` with the target branch (`origin/<branch>` when the clone has it);
   - on the default branch, including GitHub's checkout of a push to it, everything since the
     latest release tag on the line `composer.json` names (see
     [Releasing from the section](#releasing-from-the-section)).

   The changed files are those that differ between that base and the working tree, plus untracked
   files, so a local run judges what a commit would ship. When any of them falls under a watched
   path, the section must have gained an entry since the base, or a commit in the range must carry
   the trailer `Changelog: none — <reason>`.

3. **A new or tightened runtime requirement is recorded as breaking.** When `composer.json`'s
   `require` gained a package or changed a constraint since the base, `## Unreleased` must have a
   `### Changed — breaking` entry. A consumer that cannot meet the requirement cannot install the
   release. A removed requirement and `require-dev` changes are not breaking; a `composer.json`
   absent at the base has no earlier contract to break.

History the check cannot read fails it, with the fetch that supplies it: a shallow clone, a
default branch the clone cannot name, a target branch or release tag it does not have, a missing
merge base. A check that passed on no evidence would be indistinguishable from one that looked.
In GitHub Actions that means `fetch-depth: 0` on `actions/checkout`.

## Enabling it

Off by default. A project switches it on in `qaConfig/qa.php` and names the paths whose changes a
consumer can notice:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withChangelogCheck(true)
    ->withChangelogWatchedPaths('src/', 'bin/', 'config/', 'composer.json', 'templates/*.yml');
```

A path ending `/` watches the directory beneath it; anything else is a file name or an `fnmatch`
pattern in which `*` crosses directory separators. `useChangelogCheck=1` in the environment
switches the lane on too, but the watched paths still come from `qaConfig/qa.php`: enabling the
lane with none refuses to build the configuration, because a lane watching nothing would pass
every change unexamined.

On the default branch the lane needs a `<major>.N.N` release tag (see below), so a project with
no release yet tags `<major>.0.0` first.

## How it runs

- In the full pipeline, in the linting phase, immediately after the Version Pins check.
- Standalone: `vendor/bin/qa -t changelog`, alias `-t cl`.

## How to fix a failure

- **An invalid section**: each line names the line number and the construction expected. Merge a
  repeated heading into one, move stray text into an entry under the right heading, rename an
  unknown heading to the one from the table that fits, and delete a heading with no entries.

- **A watched change with no entry**: add one under the heading that fits:

  ```bash
  vendor/bin/changelog-release add-entry fixed "**The lock is released on SIGTERM.** ..."
  ```

  When no consuming project could notice the change (a refactor, a comment, a test helper that
  happens to live under `src/`), give one commit in the range the trailer instead, with a reason
  of at least two words:

  ```bash
  git commit --trailer 'Changelog: none — internal refactor, no behaviour change'
  ```

  A bare `Changelog: none`, or a reason like `wip`, is refused and listed as ignored.

- **A requirement with no breaking entry**: add the entry under `### Changed — breaking`, saying
  what a consumer now needs.

- **Missing history**: fetch it. `git fetch --unshallow` and `git fetch --tags` locally;
  `fetch-depth: 0` on `actions/checkout` in CI; `git remote set-head origin --auto` when the
  default branch cannot be named.

## Releasing from the section

`bin/changelog-release` runs from the project root. Only the result goes to stdout, so
`$(changelog-release next-version)` is exactly a version or empty; everything a person reads goes
to stderr. Exit 0 on success, 1 on any refusal.

| Command                      | Does                                                                                                  |
| ---------------------------- | ----------------------------------------------------------------------------------------------------- |
| `next-version`               | prints the version `## Unreleased` releases as; nothing when the section has no entries               |
| `apply <version> <date>`     | moves the section into `## <version> — <YYYY-MM-DD>` under a fresh, empty `## Unreleased`             |
| `notes <version>`            | prints the tag annotation: the title, a `BREAKING:` line first when needed, then the entries          |
| `add-entry <heading> <text>` | adds `- <text>` under a heading (its label or slug: `changed-breaking`, `fixed`, ...), in table order |

The version is `<major>.<minor>.<patch>`. The major is the PHP line written without the dot, read
from `composer.json`'s `require.php`, which must be a single `^X.Y` constraint (`^8.5` is line
`85`; anything else is refused rather than guessed). The minor and patch move on from the newest
tag matching `<major>.N.N` exactly, compared numerically; a line with no tag starts at
`<major>.0.0`. `apply` leaves every byte outside the section as it was, and refuses an empty
section or a version that already has a section. `notes` writes headings as underlined text, not
`###`, because git's default tag-message cleanup deletes lines that start with `#`.

How php-qa-ci itself uses these in CI is in
[CLAUDE/releases.md](../../CLAUDE/releases.md); the version scheme for consumers is under
[Branches and versions](../../README.md#branches-and-versions).

## Why a lane of its own

No other lane reads the changelog or asks whether a change was recorded, a user working on a
release types `-t changelog` on its own, and its line in the help text names no defect. It joins
the inspection the others do not: what a range of history changed, judged against the record of
it.

## Implementation

- Section parsing: [`ChangelogParser`](../../src/Changelog/ChangelogParser.php), headings and
  bumps in [`ChangelogHeadingEnum`](../../src/Changelog/ChangelogHeadingEnum.php).
- The judgement: [`ChangelogCheck`](../../src/Changelog/ChangelogCheck.php), with the range from
  [`ChangelogRangeResolver`](../../src/Changelog/ChangelogRangeResolver.php), the git probes in
  [`ChangelogGit`](../../src/Changelog/ChangelogGit.php) (argv subprocesses through the pipeline's
  process runner), and the trailer and requirement decisions in
  [`ChangelogTrailers`](../../src/Changelog/ChangelogTrailers.php) and
  [`ComposerRequirementChanges`](../../src/Changelog/ComposerRequirementChanges.php).
- The lane: [`ChangelogTool`](../../src/Pipeline/Lane/ChangelogTool.php).
- Releasing: [`ChangelogReleaseCommand`](../../src/Changelog/ChangelogReleaseCommand.php) behind
  `bin/changelog-release`, with [`ReleaseVersionCalculator`](../../src/Changelog/ReleaseVersionCalculator.php),
  [`ChangelogReleaseWriter`](../../src/Changelog/ChangelogReleaseWriter.php),
  [`ReleaseNotesRenderer`](../../src/Changelog/ReleaseNotesRenderer.php) and
  [`ChangelogEntryAdder`](../../src/Changelog/ChangelogEntryAdder.php).
