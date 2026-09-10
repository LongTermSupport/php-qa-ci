# Yaml Lint

**Identifier**: `phpqaci.yamlLint`

A check that every YAML file under the configured yaml directories parses, via the standalone
`yaml-lint` script that `symfony/yaml` ships.

## What it is about

Configuration is YAML on a great many projects, and a mis-indented service definition or an
unquoted value breaks container compilation at the next cache warm-up, often on a machine other
than the one that made the edit. Linting the configuration on every run catches the error where
it was made. `--parse-tags` lets the linter accept the custom tags Symfony config relies on, such
as `!php/const` and `!tagged_iterator`; on a project that uses none, the flag changes nothing.

## How it runs

- Gated on the library, not on the platform: the lane runs on any project whose vendor tree holds
  `symfony/yaml` (which ships `Resources/bin/yaml-lint`) and `symfony/console` (which the script
  needs). Either absent, it skips cleanly with a message. A Symfony application always has both;
  a generic project gets the lane by requiring them.
- In the full pipeline, in the linting phase after the generic lanes.
- Standalone: `vendor/bin/qa -t yamlLint` (alias `-t yaml`).
- The configured directories default to `<projectRoot>/config` on every platform and can be
  changed with `withYamlDirectories()` in the project's `qaConfig/qa.php`.
- `yaml-lint` errors on a path that does not exist, so the lane first drops every configured
  directory that is missing. When none is left it skips with a message naming what was checked;
  a project without a `config/` directory has nothing to lint.
- Otherwise it runs `vendor/symfony/yaml/Resources/bin/yaml-lint --parse-tags <existing dirs...>`
  from the project root, without Xdebug. Any non-zero exit fails the lane with the identifier
  trailer.

Twig Lint is the contrasting case: `lint:twig` needs the application's own Twig environment
(the bundle's extensions, functions and paths) and no standalone linter exists, so that lane
stays a Symfony platform lane driven through `bin/console`.

## How to fix a failure

The linter names the file and line of each parse error. Fix the YAML; the lane never modifies
files.

## Implementation

- Lane: [`YamlLintTool`](../../src/Pipeline/Lane/YamlLintTool.php).
