---
paths:
  - "ci.bash"
  - "bin/**"
  - "build/**/*.bash"
description: Bash in php-qa-ci locates, sets and hands off; logic is PHP
---

# Shell scripts and bin entry points

You are touching a shell script or a `bin/` entry point: keep it thin, and put any branching, parsing or deciding in a class under `src/`.

Every git-tracked shell script must pass ShellCheck at `warning`.

See [architecture.md](../../CLAUDE/architecture.md#bash-is-for-invocation-php-is-for-logic-owner-ruling-non-negotiable) for the ruling and its worked example.
