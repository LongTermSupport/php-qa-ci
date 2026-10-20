# Documented qa Commands

**Identifier**: `phpqaci.documentedQaCommands`

An always-on check that every `qa -t <token>` command written in the project's documentation
and agent files names a tool `bin/qa` accepts, and gives `-p` only to a tool that takes a path.

## What it is about

Agents and people copy these commands verbatim. A token the tool registry rejects stops the
reader with `Invalid tool` at the moment they were told to verify their work, and nothing else
reads the strings: the registry is the truth and the documents are written by hand. Tokens are
case-sensitive (`allCS` is a token, `allCs` is not), and a lane's canonical name is a token only
when it is also one of its aliases (`ml` and `markdown` run `markdownLinks`; the name itself
does not).

## What is checked

Every line of every `.md` file in:

- `README.md` and `CLAUDE.md` in the project root;
- `docs/`, `CLAUDE/`, `.claude/agents/`, `.claude/skills/`, `qaConfig/` and `templates/`.

A command is read when `qa` is a command word, optionally behind a bin-dir prefix
(`vendor/bin/`, `bin/`, `{bin}/`), followed by `bin/qa`'s own options and then `-t` with a
literal token. Fenced code blocks are read: that is where commands are written. Each token is
checked against the project's own registry, with `qaConfig/pipeline.php` applied, so a tool or
phase the project adds is a token its documents may use.

A command that passes `-p` (before or after `-t`) to a tool that takes no path is reported too:
`bin/qa` refuses it just as it refuses an unknown token. The phase runners (`allCS`, `allLints`,
`allStatic`, `allTests`) take no path; `vendor/bin/qa -h` and the identifier index say which
tools do.

## What is deliberately not reported

- **Placeholders**: `<tool>`, `{tool}` and the like are not tokens. Write a placeholder in angle
  brackets or braces; a bare word reads as a token.
- **Prose about the flag**: "`qa` with neither `-t` nor `-p`" is not a command.
- **A token the same document registers**: a page that shows a `qaConfig/pipeline.php` example
  registering a tool (`new ToolDefinitionDto(...)` or `new PhaseDto(...)`) may run that tool's
  tokens, because the reader is shown how to make them valid. A token registered in another
  document is still reported.
- **Plan folders** (`CLAUDE/Plan/`): a plan records what was run on the day the work was done.
  It is a record rather than an instruction, and a renamed token in one is history.
- **`CHANGELOG.md`**, which is outside the scanned set for the same reason.

## How to fix a failure

Each finding names the file, the line, the command and the token, and, where one resembles it,
the token the writer most likely meant:

```text
<file>:<line>
    <command> — '<token>' is not a tool bin/qa accepts — did you mean -t <suggestion>?
```

Replace the token with one `vendor/bin/qa -h` lists. Where the command scopes a phase runner to
a path, name the phase's path-taking tools one by one instead (the coding-standards phase on
changed files is `-t r -p <path>` then `-t f -p <path>`). If the command was meant to name a tool the
project adds, register the tool in `qaConfig/pipeline.php`
([Extending the pipeline](../extending-the-pipeline.md)). If it was meant as a placeholder,
write it as one (`<tool>`).

## Running it

```bash
vendor/bin/qa -t dqc         # or -t documentedQaCommands
vendor/bin/qa                # in the linting phase, straight after docsProse
```

## Why this is a separate lane from `markdownLinks` and `docsProse`

All three read markdown, but they ask different questions over different files: whether a link
resolves (README and `docs/`), what a sentence is about (README and `docs/`), and whether a
command runs (the documentation and the agent and skill files an agent is handed), against the
tool registry. A lane prints one identifier, and that identifier must resolve to a page about
the rule that fired.

## Class record

- **Class**: a `qa -t` command written in shipped or agent-facing documentation that `bin/qa`
  refuses: a token the tool registry does not accept, or `-p` on a tool that takes no path.
- **Hazard**: an agent following the document runs a command that `bin/qa` refuses instead of
  the check it was told to run, at the point it verifies its own work.
- **Searches**: a text search over every tracked markdown file for `qa` followed by `-t` and a
  token, each token resolved against `ToolRegistry`; and a reading of the agent and skill files
  php-qa-ci deploys (`scripts/lib/deploy-manifest.inc.bash`) for every instruction to run a lane,
  each run through `bin/qa`. Both found the seven `allCs` instances in the three deployed fixer and
  conflict-checker agents and the placeholder `toolname`, which reads as a token. Running the
  corrected `allCS` with `-p` showed the wider class: `bin/qa` refuses a path on a phase runner
  before it resolves the token, so three of the seven were wrong twice.
- **Next wider rule**: every `bin/qa` invocation in shell scripts and CI workflows as well as in
  markdown. Not built here: scripts and workflows execute their commands, so a rejected token
  there fails loudly in the run that uses it, where a document's fails only in a reader's hands.
- **Runner check**: `QaCommandTokenScannerTest` and `DocumentedQaCommandsToolTest` pin the
  reported behaviour; `ToolRegistryTest` pins that `accepts()` answers what `-t` resolves.

## Implementation

- Scanner: [`QaCommandTokenScanner`](../../src/Markdown/QaCommandTokenScanner.php); lane
  [`DocumentedQaCommandsTool`](../../src/Pipeline/Lane/DocumentedQaCommandsTool.php).
