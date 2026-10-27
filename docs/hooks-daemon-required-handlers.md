# Hooks daemon: the handler settings php-qa-ci enforces

A project that runs the [Claude Code hooks daemon](https://github.com/Edmonds-Commerce-Limited/claude-code-hooks-daemon)
gets these settings in `handlers.pre_tool_use` of its `.claude/hooks-daemon.yaml` on every
`composer install` and `composer update`:

| Setting                                | Value  |
| -------------------------------------- | ------ |
| `git_stash.enabled`                    | `true` |
| `git_stash.options.mode`               | `deny` |
| `plan_time_estimates.enabled`          | `true` |
| `validate_instruction_content.enabled` | `true` |
| `markdown_organization.enabled`        | `true` |

## The file is edited, not rewritten

The config is hand-maintained and its comments record why each setting is as it is, so the
step is a text edit and nothing else in the file moves:

- A setting that is already right is not written: the file is byte for byte as it was.
- A missing setting is added at the file's own indent; a different value replaces that one
  line and keeps its trailing comment.
- A layout that cannot be edited that way (a populated flow mapping such as
  `git_stash: {enabled: true}`, a block where a scalar belongs, CRLF line endings) leaves the
  file untouched and prints the setting that blocked it and the five settings above to apply
  by hand. The deploy carries on.

`vendor/bin/hooks-daemon-required-handlers [<config>]` repeats the step by hand. It needs no
daemon venv. [subagent_full_qa_blocker](hooks-daemon-full-qa-blocker.md) is configured by its
own step, after this one.
