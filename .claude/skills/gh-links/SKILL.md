---
name: gh-links
description: |
  How to reference commits, issues, PRs, files and lines in GitHub-posted prose:
  every reference MUST be a fully clickable Markdown link, never bare text.

  Use when (ALWAYS, before posting):
  - Writing a `gh issue comment` or `gh pr comment` body
  - Writing a `gh issue create` or `gh pr create` body/description
  - Writing PR review comments
  - Any prose that will be posted to GitHub and mentions a commit hash,
    another issue/PR, a file path, or a code line

  Applies to sub-agents too — any agent posting to GitHub follows this.
allowed-tools: Bash, Read
---

# GitHub references must be clickable links

## The rule (standing)

In **any** text posted to GitHub — issue comments, PR comments, issue/PR
bodies, review comments — **every reference to a related thing is a full,
clickable Markdown link, never bare text.** That covers:

- commit hashes
- other issues and PRs (same repo or another repo)
- files and directories
- specific code lines

Bare `abc1234`, `#123`, or `src/Foo.php` in a comment is not acceptable, even
though GitHub sometimes auto-links same-repo issue numbers and full SHAs — it is
unreliable (short SHAs, file paths, and cross-repo refs do not auto-link) and
the reader must be able to click straight through in every case.

## How to build the URLs

First get the repo slug once (owner/name):

```bash
gh repo view --json nameWithOwner -q .nameWithOwner   # e.g. owner/repo
```

Then, with `OWNER/REPO` (and the branch/sha for file links):

| Reference                  | Markdown to write                                                              |
| -------------------------- | ------------------------------------------------------------------------------ |
| Commit                     | `` [`abc1234`](https://github.com/OWNER/REPO/commit/abc1234) ``                |
| Issue (same or other repo) | `[#123](https://github.com/OWNER/REPO/issues/123)`                             |
| Pull request               | `[#123](https://github.com/OWNER/REPO/pull/123)`                               |
| File                       | `[path/to/File.ext](https://github.com/OWNER/REPO/blob/main/path/to/File.ext)` |
| File at a line             | `[File.ext:42](https://github.com/OWNER/REPO/blob/main/path/to/File.ext#L42)`  |
| Line range                 | append `#L42-L58` to the blob URL                                              |
| Thing in a different repo  | full `https://github.com/OTHER_OWNER/OTHER_REPO/...` URL                       |

Notes:

- For files, use a **stable ref** in the URL — a commit SHA or the default
  branch — that already **exists on the remote**. Never link a local
  feature branch (or unpushed commits) that a reader cannot reach; if the
  target is not yet pushed, push it first or omit the link.
- Link the label to what it says: show the short SHA / `#num` / file name as the
  link text, not a naked long URL.

## Workflow

Compose the body in a temp file, put the links in, then post with `--body-file`
(avoids shell-escaping and keeps the links intact):

```bash
BODY="$(mktemp)"
# write the comment body — with clickable links — into "$BODY", then:
gh issue comment 123 --repo OWNER/REPO --body-file "$BODY"
gh pr comment 123 --repo OWNER/REPO --body-file "$BODY"
```

Before posting, re-scan the body: any bare hash, bare `#num`, or bare path that
names a real thing must become a link.
