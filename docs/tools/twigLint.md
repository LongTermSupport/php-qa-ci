# Twig Lint

**Identifier**: `phpqaci.twigLint`

A Symfony-only check that every Twig template under the configured twig directories parses,
via the project's own `bin/console lint:twig`.

## What it is about

A Twig syntax error only surfaces when the template is rendered, so a broken template in a
rarely used page ships unnoticed until a user hits it. Linting every template on every run
turns that runtime error into a build failure.

## How it runs

- Only on a Symfony project (detected via `symfony.lock`); on any other platform the lane is
  skipped before doing anything.
- In the full pipeline, in the linting phase with the other Symfony lanes.
- Standalone: `vendor/bin/qa -t twigLint`.
- The lane first lists the console's commands (`bin/console`, output captured) and skips with
  "Twig Lint not found in bin/console, skipping" when no `lint:twig` command is registered.
- It also skips with "Twig Not Installed, nothing to do" when `vendor/symfony/twig-bundle` is
  not a directory.
- Otherwise it runs `bin/console lint:twig <twigDirectories...>` from the project root, without
  Xdebug. The directories default to `<projectRoot>/templates` and can be changed with
  `withTwigDirectories()` in the project's `qaConfig/qa.php`.
- Any non-zero exit of the linter fails the lane with the identifier trailer.

## How to fix a failure

The linter names the template and line of each syntax error. Fix the template; the lane never
modifies files.

## Implementation

- Lane: [`TwigLintTool`](../../src/Pipeline/Lane/TwigLintTool.php).
