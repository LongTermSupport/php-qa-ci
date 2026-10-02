# Hooks daemon: the full pipeline is the coordinator's

The [Claude Code hooks daemon](https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon)
ships `subagent_full_qa_blocker` from v3.67.0: a PreToolUse handler that denies a declared
full-suite QA run when a **sub-agent** starts it, and names the targeted commands to run
instead. php-qa-ci declares it for the `qa` pipeline on every `composer install` and
`composer update`, so in a multi-agent session the full pipeline runs once, in the
coordinating session, and sub-agents run targeted QA. The main session is never affected.

## What a sub-agent may and may not run

| Command (any bin dir, any env prefix, wrapper or `bash -c`) | In a sub-agent |
| ----------------------------------------------------------- | -------------- |
| `bin/qa`, `CI=true bin/qa`, from any directory              | denied         |
| `bin/qa -p src`, `-p tests`, `-p .` (the whole tree)        | denied         |
| `php bin/qa`                                                | denied         |
| `bin/qa -t <tool>`, with or without `-p`                    | allowed        |
| `bin/qa -p <a path below src/ or tests/>`                   | allowed        |
| `bin/qa --agent-mode -t phpstan -p <file>`                  | allowed        |

A deny lists, under "RUN INSTEAD", the targeted commands written into the config, with the
project's own bin dir.

## Why only the pipeline counts as full

- **The pipeline is the gate.** QA has passed when `CI=true bin/qa` exits 0, and a single
  lane is a working tool, not evidence. The gate belongs where the merged work is: the
  coordinating session, after every editing agent has finished.
- **The daemon cannot tell lanes apart.** A pattern sees `-t phpunit` and `-t composer` the
  same way unless the lane name itself makes the run full, and a word that makes a run full
  cannot then be narrowed by `-p`. Per-lane patterns would therefore either deny targeted
  runs such as `-t phpunit tests/Unit/FooTest.php`, or deny every project-wide lane,
  including the ones that take no path at all.
- **One tree, one run.** php-qa-ci's run lock already refuses a second concurrent `qa` run in
  the same project tree (exit 75), so lanes started by several agents in one checkout queue
  rather than pile up. Agents working in separate worktrees can still each run a
  project-wide lane; the orchestration guidance asks them to scope with `-p` instead.

## Where the full pipeline runs instead

The coordinating session runs it itself, in the background, with the transcript in a log:

```bash
CI=true bin/qa > var/qa/full-pipeline.log 2>&1
```

The exit code is the verdict. When it is not 0, the `php-qa-ci_full-pipeline-runner` agent
reads the log and returns a per-lane summary; it never runs the pipeline itself.

## How the config is written

- **Detection.** The step runs from the deploy's hooks-daemon phase, against the
  `.claude/hooks-daemon.yaml` that phase found (the project's, or its parent's in a
  monorepo). The daemon's version is read from
  `.claude/hooks-daemon/src/claude_code_hooks_daemon/version.py`; no install, or a version
  before 3.67.0, leaves the config untouched with a one-line notice.
- **Comments are kept.** The edit is a text edit: only the handler's own lines are written,
  and every other byte of the file stays as it was. A layout that cannot be edited that way
  (a non-empty flow mapping such as `pre_tool_use: {a: 1}`, or CRLF line endings) is
  reported, not guessed at.
- **Ownership.** php-qa-ci rewrites the block only while it carries the
  `# Managed by php-qa-ci` comment. Delete that comment to own the block; set
  `enabled: false` to opt out (php-qa-ci then leaves it alone, marker or not). A block the
  project wrote itself is never touched.
- **Validation.** The new text is written to a candidate file beside the config and checked
  with `.claude/hooks-daemon/bin/hooks-daemon config-validate`. Only an accepted candidate
  replaces the config, keeping its permissions; a rejection, or a daemon CLI that cannot
  run, leaves the config as it was.
- **Restart.** A running daemon keeps its old config until it restarts, so the step prints
  the restart command instead of running it: composer may run where that daemon is not
  reachable, and restarting it would interrupt whichever session owns it.
- **By hand.** `vendor/bin/hooks-daemon-full-qa-blocker [<project-root> [<config>]]`
  repeats the step. `PHP_QA_CI_DISABLE_CONFIG_PUSH=true` skips the whole deploy, this step
  included.

## The block

For a project whose Composer `bin-dir` is the default `vendor/bin`:

```yaml
handlers:
  pre_tool_use:
    subagent_full_qa_blocker:
      # Managed by php-qa-ci: rewritten on composer install/update while this
      # comment stays. Delete the comment to own the block, or set enabled: false
      # to opt out. See php-qa-ci docs/hooks-daemon-full-qa-blocker.md.
      enabled: true
      priority: 32
      options:
        full_qa_patterns:
          # The full pipeline: qa with neither -t nor -p, from any directory.
          - id: php-qa-ci-full-pipeline
            command: qa
            read_only_flags: ["-t", "-p", "-h"]
          # The full pipeline pointed at the whole tree: -p src, -p tests, -p .
          - id: php-qa-ci-full-pipeline-whole-tree
            command: qa
            full_args: [src, tests, test]
            read_only_flags: ["-t", "-h"]
          # The full pipeline through the PHP binary: php vendor/bin/qa
          - id: php-qa-ci-full-pipeline-via-php
            command: php
            full_words: [vendor/bin/qa, ./vendor/bin/qa]
            read_only_flags: ["-t", "-p", "-h"]
        targeted_qa_commands:
          - "vendor/bin/qa -t <tool> -p <file or directory you changed>"
          - "vendor/bin/qa --agent-mode -t phpstan -p <file you changed>"
          - "vendor/bin/qa -t <tool>   (one lane; never the bare pipeline)"
        unseen_sink_description: "php-qa-ci's run lock refuses a second concurrent qa run in the same project tree (exit 75); nothing else here stops a full run this handler could not read."
```

`scope` is left at the handler's default (sub-agents only); any other scope would deny the
coordinator's own run.

## Known limits

- **Spellings that are denied although targeted.** `-t` and `-p` glued to their values
  (`-tstan`, `-psrc/Foo.php`) and a bare path with no `-t` (`bin/qa src/Foo.php`) read as the
  full pipeline. Spell them `-t stan`, `-p src/Foo.php`.
- **A whole tree in a monorepo.** `-p src` is judged against the repository root, so in a
  project nested inside a larger repository it reads as targeted. The bare pipeline is still
  denied there.
- **What the daemon cannot read.** A command it cannot resolve (a variable it never sees
  set, a script it cannot read) is allowed with an advisory under the daemon's default
  `unseen_policy`. Composer scripts are not followed: `composer qa` is not seen.
