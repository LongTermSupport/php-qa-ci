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
- A layout that cannot be edited that way leaves the file untouched and prints the key that
  blocked it and the five settings above to apply by hand. The deploy carries on.

### Edited in place

The editor works on plain block mappings along the whole path to each setting
(`handlers`, `pre_tool_use`, the handler, and `options` for the mode):

- unquoted keys, each key once in its block;
- space indentation, every key of a block at the same indent;
- a key line may carry a trailing comment, kept as written;
- a missing `handlers`, `pre_tool_use`, handler or `options` key, a key with no value, and an
  empty `{}` value (opened into a block, its comment kept);
- `true`, `yes` and `on` count as already true; `deny`, `"deny"` and `'deny'` as already
  `deny`;
- anything off that path, whatever its shape: lists, anchors, quoted keys, flow collections,
  block scalars.

### Refused, file untouched

- a quoted or complex key (`"handlers":`, `'git_stash':`, `? key`) in any block on the path,
  siblings included;
- a block list where a mapping belongs (`pre_tool_use:` followed by `- item`);
- a tab in the indentation anywhere in the file;
- a key that appears twice in a block on the path (the loader keeps the last, so an edit to
  the first would not take effect);
- a flow collection (`{...}`, `[...]`) on the path, other than the empty `{}`, and any scalar
  where a block belongs (`handlers: ~`);
- an anchor, alias or tag (`&a`, `*a`, `!!bool`) or a block scalar (`|`, `>`) on the path;
- a merge key (`<<:`) in a block on the path;
- more than one YAML document (`---` after content, or `...`);
- CRLF line endings, which are refused rather than converted;
- lines indented less than the other keys of their block, or an indented top level.

### Checked before anything is written

The new text is read back before the file is written. The write happens only if the new text
is still a layout the editor reads, all five settings read as required, and a line diff
against the original shows nothing moved but lines of the enforced keys (and no more removed
lines than settings changed). Otherwise the file is left untouched and the step prints why and
the five settings to apply by hand.

`vendor/bin/hooks-daemon-required-handlers [<config>]` repeats the step by hand. It needs no
daemon venv. [subagent_full_qa_blocker](hooks-daemon-full-qa-blocker.md) is configured by its
own step, after this one.
