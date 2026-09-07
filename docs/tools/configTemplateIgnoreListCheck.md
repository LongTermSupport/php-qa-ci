# Config Template Ignore-List Audit

**Identifier**: `phpqaci.configTemplateIgnoreList`

An always-on self-check that audits php-qa-ci's own shipped `configDefaults/generic/` directory,
not a consuming project's tree. It fails the build unless every namespace-less config template
there is matched by a pattern in `psr4-validate-ignore-list.txt`.

## What it is about

[docs/configuration.md](../configuration.md), under "Config Overrides", tells a consumer to copy a
file from `configDefaults/generic/` into the project's `qaConfig/` folder to override it. php-qa-ci
also maps `qaConfig/` as a PSR-4 root, which `psr4Validate` checks on every consuming project.

Most files under `configDefaults/generic/` are templates: plain `return ...;` scripts with no
namespace, by design. Copying one into `qaConfig/` creates a file `psr4Validate` reports as a
"Parse Error" unless its destination is matched by a pattern in the shipped ignore list.

## Why it exists

The ignore list carried two hand-added entries from the first time a consumer hit this, then a
later template shipped without one and a consumer following the documented procedure hit it
again. A Defence Before Fix execution test found eight uncovered templates at once. Nothing
enforced that every namespace-less template stays covered as templates are added; this check does.

## How it runs

- In the full pipeline, in the linting phase, immediately after the package type check.
- Standalone: `vendor/bin/qa -t configTemplateIgnoreList`, alias `-t cti`.
- Binary: `bin/config-template-ignorelist-check`, delegating to
  `LTS\PHPQA\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListCheck::main()`.

The check scans php-qa-ci's own `configDefaults/generic/`, resolved relative to its own class
file, so it is meaningful whether php-qa-ci is the root project or installed under a consumer's
`vendor/`. The override directory it assumes is the one the documentation names, and a test
fails if the documentation stops naming it.

## How to fix a failure

Add a line to `configDefaults/generic/psr4-validate-ignore-list.txt` matching the new template's
`qaConfig/`-relative destination, in the shape of the existing entries:

```text
#qaConfig/<name>\.php#
```

If the new file has a real `namespace` declaration it is a class, not a template, and needs no
entry; the check only flags namespace-less files.

## Implementation

- Decision: [`ConfigTemplateIgnoreListAuditor`](../../src/ConfigTemplateIgnoreList/ConfigTemplateIgnoreListAuditor.php),
  which reads the templates and the ignore list as text and never executes either.
- Runner: [`ConfigTemplateIgnoreListCheck`](../../src/ConfigTemplateIgnoreList/ConfigTemplateIgnoreListCheck.php),
  which maps the verdict to output and exit code and prints the identifier.
- Deliberately separate from [`Psr4Validator`](../../src/Psr4Validator.php), which validates a
  consuming project's tree against its own `composer.json`; this audits the toolchain's shipped
  templates against the list it ships.
