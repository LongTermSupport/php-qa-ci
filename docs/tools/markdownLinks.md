# Markdown Links Checker

**Identifier**: `phpqaci.markdownLinks`

An always-on check that every link in `README.md` and in every `*.md` file under `docs/`
resolves: internal links to a file that exists, external links to a URL that answers.

## What it is about

Documentation rots one moved file at a time. A link to a page that was renamed or deleted keeps
reading fine until someone follows it. Checking every link on every run keeps the documentation
tree navigable and catches a doc that was moved without its references being updated.

## How it runs

- In the full pipeline, in the linting phase, immediately before
  [Documentation Prose](docsProse.md), which reads the same files.
- Standalone: `vendor/bin/qa -t ml` (alias `-t markdown`).
- In this repository's own checkout, the Claude Code hooks daemon also checks links at
  Write/Edit and commit time (`pointer-resolves`). That is a guardrail for the person
  editing, seconds after the edit; this lane is the package's guarantee to every consumer
  on every run. Both are kept deliberately — they answer at different moments for different
  audiences — and this lane is the one that ships.
- A project must have a `README.md` in its root; without one the lane fails, because the check
  has nothing to stand on. A root `README.md` that is a dangling symlink also fails the lane, with
  a message naming it as a symlink whose target does not exist; it is not skipped like a file
  under `docs/`.
- GitHub URLs are skipped when no `GH_TOKEN` / `GITHUB_TOKEN` is set, since a private repository
  answers 404 anonymously and cannot be told from a dead link.
- A file under `docs/` whose real path cannot be resolved, such as a dangling symlink, is not
  read; it used to stop the lane without naming it
  ([#241](https://github.com/LongTermSupport/php-qa-ci/issues/241)). The lane prints
  `Markdown links skipped <path>: its real path cannot be resolved ...` for each one, and a
  skipped file does not fail the lane.

## What fails and what only warns

An internal link to a file that does not exist fails the lane, and so does an external link the
server says is gone: HTTP 404 or 410. Nothing else about an external link fails it, because
the lane cannot tell a dead page from a host having a bad minute:

| Answer                                                         | Result  | Printed as                                           |
| -------------------------------------------------------------- | ------- | ---------------------------------------------------- |
| 2xx                                                            | passes  | nothing                                              |
| 301, 302, 307, 308 ending on a 2xx                             | warning | `redirected to <address>; update the link`           |
| 5xx, such as a 502 from a host that is down                    | warning | `a server error is not treated as a broken link`     |
| 401, 403, 429 or any other 4xx but 404 and 410                 | warning | `the server would not say whether the page exists`   |
| No answer: connection refused, a timeout, a certificate error  | warning | the reason, then `is not treated as a broken link`   |

A warning is printed under the file, as `Warning for "<text>" to "<target>"`, and never changes the
exit code. Fix a redirect warning when convenient by pointing the link at the address it ends
on. A warning that never goes away is worth a look: a link that answers 5xx or does not answer on
every run is probably dead.

## How to fix a failure

Each finding names the file, the link text and the target. Fix the path or URL, or remove the
link if the target is gone for good. A warning needs no fix to pass.

## Implementation

- Runner: [`LinksChecker`](../../src/Markdown/LinksChecker.php); lane
  [`MarkdownLinksTool`](../../src/Pipeline/Lane/MarkdownLinksTool.php); standalone binary
  `bin/mdlinks`.
