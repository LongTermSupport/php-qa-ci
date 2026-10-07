# Markdown Format

**Identifier**: `phpqaci.markdownFormat`

Keeps the project's markdown in the form the
[Claude Code hooks daemon](https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon)
writes it, by running the daemon's own formatter. Coding-standards phase, after the PHP and Twig
fixers.

## What it is about

The hooks daemon rewrites markdown: every `.md` file written through Claude Code's `Write` or
`Edit` tool, the whole of `CLAUDE.md` on every daemon restart (committing the result), and the
whole project in its housekeeping pass. Its transform is mdformat with the GFM extension, plus a
few fixed rules of its own: numbered lists, `---` thematic breaks, front matter kept byte for
byte.

Markdown written any other way, by a generator, a script, an editor or a `git merge`, is not in
that form, so the daemon rewrites it later. That rewrite then fights whatever wrote the file
first: a generator regenerates its form, the daemon reformats it, and both changes land in the
history. This lane closes the gap by putting the daemon's form there first.

It runs the daemon rather than reimplementing it. Byte-for-byte agreement depends on the mdformat
version the daemon's virtualenv resolved, and only the daemon's own code is guaranteed to have it.
[Plan 00018 Decision 1](../../CLAUDE/Plan/Completed/00018-markdown-formatter-matching-hooks-daemon/DECISIONS.md)
records the alternatives and why they lost.

## How it runs

- The daemon CLI is `.claude/hooks-daemon/bin/hooks-daemon`, found in the project or in a
  directory above it, up to the root of the git work tree (one daemon serving a monorepo). It is
  never looked for above that root.
- **Without the daemon the lane skips**, and says so. That includes CI, where nothing rewrites
  markdown either, so there is nothing to agree with.
- It formats the paths from `withMarkdownFormatPaths()` in `qaConfig/qa.php`. The default is
  `README.md`, `CLAUDE.md`, `CHANGELOG.md`, `docs/` and `CLAUDE/`, and a directory is formatted
  recursively. A project list replaces the default.
- A listed path that does not exist, or that git ignores (`git check-ignore`), is skipped with a
  `Not present, skipped:` or `Gitignored, skipped:` line, so a misspelt path is visible. When every
  path is skipped, so is the lane. Outside a git work tree nothing is ignored, as for the daemon.
- The daemon refuses to rewrite a gitignored file, so ignored output under a listed directory is
  left alone too.
- It does not support `-p`: the paths are configuration, like Twig's directories.

### Read-only versus writable

- **Read-only run** (`QA_READONLY=1`): `format-markdown --check` on each path. Every path is
  checked before the verdict, so one run lists every file the daemon would rewrite
  (`Would reformat: <file>`), then the lane fails with the standard "pending changes in a
  READ-ONLY run" guidance.
- **Writable run**: `format-markdown` rewrites the files in place and lists each one
  (`Reformatted: <file>`).

Any other failure, an error from the daemon, a CLI without `format-markdown` or a
`git check-ignore` that cannot run, is a **crash**:
it is not a formatting finding, and the daemon's own message is printed.

## How to fix a failure

**Pending reformatting in a read-only run**: let the daemon's formatter rewrite the files where
writes are allowed, review the diff (it is whitespace, table alignment, list numbering and
escaping, never wording), and commit it.

```bash
QA_READONLY=0 vendor/bin/qa -t mdf
git add -A && git commit
```

**A crash**: read the daemon's message above the identifier. A daemon older than its
`format-markdown` command needs upgrading through the daemon's own upgrade route.

**A path that must stay byte for byte** (a vendored specification, a test fixture holding
deliberately malformed markdown): leave it out of `withMarkdownFormatPaths()`. List the
directories the project writes itself rather than one that contains both.

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withMarkdownFormatPaths('README.md', 'CLAUDE.md', 'docs/handbook');
```

## Implementation

- Lane: [`MarkdownFormatTool`](../../src/Pipeline/Lane/MarkdownFormatTool.php).
- Locator: [`HooksDaemonCliLocator`](../../src/HooksDaemon/HooksDaemonCliLocator.php).
- See also [markdownLinks.md](markdownLinks.md), which checks that links resolve, and
  [docsProse.md](docsProse.md), which checks what the prose is about. This lane checks neither;
  it only owns the layout.
