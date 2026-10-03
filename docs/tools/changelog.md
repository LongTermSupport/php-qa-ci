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

| Heading                  | What belongs there                                                         | Asks for |
| ------------------------ | -------------------------------------------------------------------------- | -------- |
| `### Changed — breaking` | a change a consuming project must act on: a new requirement, a new failure | major    |
| `### Removed`            | a lane, option, command or file a consumer could have relied on is gone    | major    |
| `### Added`              | a new lane, option, command or rule                                        | minor    |
| `### Changed`            | a behaviour change a consumer can notice but need not act on               | minor    |
| `### Deprecated`         | something that still works and will be removed                             | minor    |
| `### Fixed`              | a defect corrected                                                         | patch    |
| `### Security`           | a vulnerability corrected                                                  | patch    |

The release takes the largest bump any heading asks for, and the project's
[versioning policy](#versioning-policies) decides what a major means: the next major under
semantic versioning, the next minor under a locked major. A section with no entries means no
release is due.

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
     newest release tag under the project's [versioning policy](#versioning-policies).

   The changed files are those that differ between that base and the working tree, plus untracked
   files, so a local run judges what a commit would ship. When any of them falls under a watched
   path, the record must have gained an entry since the base, or a commit in the range must carry
   the trailer `Changelog: none — <reason>`. The record is `## Unreleased` plus any version
   section the base does not have: between a release being written and being tagged, its entries
   are no longer under `## Unreleased`, but they still record the changes.

3. **A new or tightened runtime requirement is recorded as breaking.** When `composer.json`'s
   `require` gained a package or changed a constraint since the base, the record must have a
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

On the default branch the lane needs a release tag to measure from, so a project with no release
yet tags the one it continues from, or its first: `0.1.0` under semantic versioning,
`<major>.0.0` under a locked major, with the policy's prefix.

## Versioning policies

`withReleaseVersionPolicy()` in `qaConfig/qa.php` decides how a release is numbered, and so which
tags count as releases. Both `bin/changelog-release` and the lane read it.

| Policy                                                     | A breaking entry releases           | First release        |
| ---------------------------------------------------------- | ----------------------------------- | -------------------- |
| `ReleaseVersionPolicy::semanticVersioning()` (the default) | the next major; next minor on `0.x` | `0.1.0`, or as given |
| `ReleaseVersionPolicy::lockedMajor(3)`                     | the next minor                      | `3.0.0`              |
| `ReleaseVersionPolicy::lockedMajorFromPhpRequirement()`    | the next minor                      | `<PHP line>.0.0`     |

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withReleaseVersionPolicy(ReleaseVersionPolicy::semanticVersioning(tagPrefix: 'v', firstVersion: '1.0.0'));
```

- **Semantic versioning** is what a consumer of a library expects: after `1.4.2`, a
  `### Changed — breaking` or `### Removed` entry releases `2.0.0`, `### Added` alone releases
  `1.5.0`, and fixes alone `1.4.3`. While the major is `0` a breaking change moves the minor.
- **A locked major** is the override, for a project whose major means something else. php-qa-ci
  declares `lockedMajorFromPhpRequirement()`: the major is the PHP line `composer.json`'s
  `require.php` names, written without the dot (`^8.5` is `85`, and anything but a single `^X.Y`
  constraint is refused), so its releases run `85.2.0`, `85.3.0`, and a new PHP line is a new
  branch.
- **A tag prefix** (`tagPrefix: 'v'`) is part of the tag and never of the version: the section
  is `## 1.5.0`, the tag `v1.5.0`.

A release is a plain `X.Y.Z` with no leading zeros, and under a locked major one on that major;
tags are compared numerically. Any other tag (`1.5`, `1.5.0-rc1`, `v1.5.0` without the `v`
prefix, another major) is ignored, so a project adopting the policy with tags of another shape
sets the prefix to match them.

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

| Command                      | Does                                                                                                                                  |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `next-version`               | prints the version `## Unreleased` releases as; nothing when the section has no entries                                               |
| `apply <version> <date>`     | moves the section into `## <version> — <YYYY-MM-DD>` under a fresh, empty `## Unreleased`                                             |
| `notes <version>`            | prints the release notes: the title, a `BREAKING:` line first when needed, then the entries; takes the tag as well                    |
| `pending-tags`               | prints `<tag> <commit>` for each version section newer than the newest release tag, oldest first; the commit is the one that wrote it |
| `add-entry <heading> <text>` | adds `- <text>` under a heading (its label or slug: `changed-breaking`, `fixed`, ...), in table order                                 |
| `add-tool-updates`           | adds a `Changed` entry naming each bundled tool whose pinned version differs from `HEAD`'s; nothing when none moved                   |

The version is `<major>.<minor>.<patch>`, moved on from the newest release tag by the
[versioning policy](#versioning-policies). `apply` leaves every byte outside the section as it was, and refuses an empty
section or a version that already has a section. `notes` writes headings as underlined text, not
`###`, so the notes also survive as an annotated tag's message, whose default cleanup deletes
lines that start with `#`. `pending-tags` refuses a version section no commit has written yet.
`add-tool-updates` reads php-qa-ci's own pins (`phive.xml`'s `installed`, the ShellCheck version
file, `build/*/composer.lock`), never a tool's `--version` banner.

The shipped GitHub Actions workflow that drives these commands, and the repository settings it
needs, are under [Release automation](../github-actions.md#release-automation). How php-qa-ci
itself uses them is in [CLAUDE/releases.md](../../CLAUDE/releases.md); php-qa-ci's own version
scheme is under [Branches and versions](../../README.md#branches-and-versions).

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
  `bin/changelog-release`, with [`ReleaseVersionPolicy`](../../src/Changelog/ReleaseVersionPolicy.php),
  [`ReleaseLine`](../../src/Changelog/ReleaseLine.php),
  [`ReleaseVersionPolicyLoader`](../../src/Changelog/ReleaseVersionPolicyLoader.php) (which reads
  the policy from `qaConfig/qa.php`),
  [`ChangelogReleaseWriter`](../../src/Changelog/ChangelogReleaseWriter.php),
  [`ReleaseNotesRenderer`](../../src/Changelog/ReleaseNotesRenderer.php),
  [`ReleasedSections`](../../src/Changelog/ReleasedSections.php),
  [`BundledToolVersions`](../../src/Changelog/BundledToolVersions.php) and
  [`ChangelogEntryAdder`](../../src/Changelog/ChangelogEntryAdder.php).
