# Yaml Lint

**Identifier**: `phpqaci.yamlLint`

A Symfony-only check that every YAML file under the configured yaml directories parses, via
the project's own `bin/console lint:yaml --parse-tags`.

## What it is about

Symfony configuration is YAML, and a mis-indented service definition or an unquoted value
breaks container compilation at the next cache warm-up, often on a machine other than the one
that made the edit. Linting the configuration on every run catches the error where it was
made. `--parse-tags` lets the linter accept the custom tags Symfony config relies on, such as
`!php/const` and `!tagged_iterator`.

## How it runs

- Only on a Symfony project (detected via `symfony.lock`); on any other platform the lane is
  skipped before doing anything.
- In the full pipeline, in the linting phase with the other Symfony lanes.
- Standalone: `vendor/bin/qa -t yamlLint`.
- The configured directories default to `<projectRoot>/config` and can be changed with
  `withYamlDirectories()` in the project's `qaConfig/qa.php`.
- `lint:yaml` errors on a path that does not exist, so the lane first drops every configured
  directory that is missing. When none is left it skips with a message naming what was checked;
  a project without a `config/` directory has nothing to lint.
- Otherwise it runs `bin/console lint:yaml --parse-tags <existing dirs...>` from the project
  root, without Xdebug. Any non-zero exit fails the lane with the identifier trailer.

## How to fix a failure

The linter names the file and line of each parse error. Fix the YAML; the lane never modifies
files.

## Implementation

- Lane: [`YamlLintTool`](../../src/Pipeline/Lane/YamlLintTool.php).
