# Markdown Links Checker

**Identifier**: `phpqaci.markdownLinks`

An always-on check that every link in `README.md` and in every `*.md` file under `docs/`
resolves: internal links to a file that exists, external links to a URL that answers.

## What it is about

Documentation rots one moved file at a time. A link to a page that was renamed or deleted keeps
reading fine until someone follows it. Checking every link on every run keeps the documentation
tree navigable and catches a doc that was moved without its references being updated.

## How it runs

- In the full pipeline, last in the linting phase.
- Standalone: `vendor/bin/qa -t ml` (alias `-t markdown`).
- A project must have a `README.md` in its root; without one the lane fails, because the check
  has nothing to stand on.
- GitHub URLs are skipped when no `GH_TOKEN` / `GITHUB_TOKEN` is set, since a private repository
  answers 404 anonymously and cannot be told from a dead link.

## How to fix a failure

Each finding names the file, the link text and the target. Fix the path or URL, or remove the
link if the target is gone for good.

## Implementation

- Runner: [`LinksChecker`](../../src/Markdown/LinksChecker.php); lane
  [`MarkdownLinksTool`](../../src/Pipeline/Lane/MarkdownLinksTool.php); standalone binary
  `bin/mdlinks`.
