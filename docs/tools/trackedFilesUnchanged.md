# Tracked files unchanged by a read-only run

**Identifier**: `phpqaci.trackedFilesUnchanged`

A check of the run itself, not a lane: nobody selects it with `-t`. In a **read-only** run
(`QA_READONLY=1`, which CI sets) every lane may inspect the project and none may write to a
git-tracked file. This check fails the run when one does, naming each file and the step that was
running when it changed.

## Why it matters

A gate that exits 0 and leaves the tree dirty hands the next person, or an automated or
agent-driven workflow, an uncommitted change nobody made on purpose. A read-only run says its
fixers only dry-run and fail on a pending change, and this check holds every other lane to the same
promise instead of trusting each one to keep it.

## What it does

- Before the first step, it records which tracked files differ from what git last recorded, with a
  hash of each one's content.
- After every lane, and after the pre and post hooks, it reads that state again.
- A file whose content moved since the step before is named, with the step, as the project knows
  it: relative to the project root, and only the project's own files. When the project is a
  subdirectory of a larger repository, a sibling project's changes are not blamed on this run.
- The run fails with exit 1 under `phpqaci.trackedFilesUnchanged`. In an aggregate run it carries on
  so that later steps are still attributed, and the identifier appears in the summary; with
  `QA_FAIL_FAST=1` it stops at the step that changed the file.

## What it ignores

- **Writable runs.** A writable run changes files by design, and whether this check should ever
  apply to one is undecided, so it is not watched.
- **A tree already dirty before the run.** That is the baseline. A file in it is named only if the
  run changes its content again.
- **Untracked files.** Only files git tracks are watched.
- **A tree git cannot read.** Outside a git work tree, or when `git status` fails, the run says so
  once and carries on unchecked; it does not fail.
- **Agent mode** (`bin/qa --agent -t <lane>`), which has its own exit-code contract.

## How to fix a failure

The step named is the one that wrote the file. Run it alone read-only to see it write:

```bash
QA_READONLY=1 CI=true vendor/bin/qa -t <lane>
git status --short
```

- A fixer that rewrote a file means the file is not in the shape the fixer wants: run the run
  writable (`bin/qa`), review and commit the change.
- A lane that is not a fixer wrote a tracked file in a read-only run: that is a defect in the lane,
  to report where the lane lives. Do not add the file to an ignore list to quiet the check.
- A generated file the project tracks, rewritten by a framework tool during a lane, should not be
  tracked, or should be generated before the run rather than during it.

## Implementation notes

- [`TrackedTreeWatch`](../../src/Pipeline/Runner/TrackedTreeWatch.php) holds the before and after
  states and names what moved.
- [`GitTrackedTree`](../../src/Pipeline/Runner/GitTrackedTree.php) reads the state from
  `git diff --relative --name-only -z --no-renames HEAD` with `GIT_OPTIONAL_LOCKS=0`, so the check
  does not itself write `.git/index`, and hashes only the files git reports. A repository with no
  commit yet has no `HEAD` to compare with, which is the "git cannot read it" case above.
- [`Pipeline`](../../src/Pipeline/Runner/Pipeline.php) calls the watch around each step.
