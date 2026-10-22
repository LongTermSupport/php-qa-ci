# Release lines: where work happens (Owner decision)

The branches and what each targets are described for users in
[README.md](../README.md#branches-and-versions), and the issue forms ask for the line
(`.github/ISSUE_TEMPLATE/`, guarded by `IssueFormsTest`). This file holds the ruling those
summarise.

- **Every change, fix and feature is made on `php8.5`**, the current line.
- **`php8.4` takes a bug fix only when a project running `php8.4` reports that bug.** A bug found
  on `php8.5`, or found on `php8.4` by working on this repository, is not backported
  proactively; it stays a known `php8.4` issue until a `php8.4` project hits it. The single
  exception is #36 (FlipAssertRector inverts assertions), backported on the Owner's go-ahead.
- **`php8.3` is dead**: no fixes, no releases.
- **An issue states the release line it was found on**, so a report against `php8.4` is
  recognisable as the trigger for a backport. Changing which older lines take fixes is changing
  this ruling, and `IssueFormsTest::MAINTAINED_OLDER_LINES` with it.
