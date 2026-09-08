# GitHub Actions PHP Version Detection Check

**Identifier**: `phpqaci.githubActionsPhpVersion`

An always-on check that every GitHub Actions workflow under `.github/workflows/` and every
shipped template under `templates/github-actions/` that derives a PHP version from
`composer.json` can select, and defaults to, the PHP version `composer.json` requires. When
both the shipped consumer template `templates/github-actions/php-qa-ci.yml` and the workflow
this repository runs, `.github/workflows/qa.yml`, exist, they must be identical.

## What it is about

The shipped workflows pick a runner PHP by matching `composer.json`'s constraint against a
hand-written list of versions, in one of two shapes:

```bash
PHP_VERSION=8.5
for V in 8.5 8.4 8.3 8.2 8.1 8.0; do
  if [[ "$CONSTRAINT" == *"$V"* ]]; then PHP_VERSION="$V"; break; fi
done
```

```bash
if [[ "$PHP_CONSTRAINT" == *"8.5"* ]]; then
  PHP_VERSION="8.5"
elif ...
else
  PHP_VERSION="8.5"
fi
```

When the PHP requirement moves on and one list is missed, that workflow quietly selects an
older PHP for a project on the new one. Nothing fails; the wrong runtime runs the checks.

## How it runs

- In the full pipeline, in the linting phase, immediately after the PHPUnit config version
  check.
- Standalone: `vendor/bin/qa -t githubActionsPhpVersion`, alias `-t gapv`.
- Binary: `bin/github-actions-php-version-check <project-root>`, delegating to
  `LTS\PHPQA\GithubActions\WorkflowPhpVersionCheck::main()`.

The required version is the major.minor in `composer.json`'s `require.php`. A workflow with no
version detection is not judged. A project whose `composer.json` declares no PHP major.minor
passes with a note. The template-identity check applies only where both files exist, so it
is a self-check of this repository and does not affect a consuming project.

## How to fix a failure

Add the required version to the front of each reported list and set the fallback default to
it, as the message spells out. For the template-identity failure, copy the changed file over
the other so the two are identical again.

## Implementation

- Decision: [`WorkflowPhpVersionDetector`](../../src/GithubActions/WorkflowPhpVersionDetector.php),
  which reads the version lists and defaults out of the workflow text.
- Runner: [`WorkflowPhpVersionCheck`](../../src/GithubActions/WorkflowPhpVersionCheck.php),
  which reads composer.json and the workflow files, maps the verdict to output and exit code,
  and prints the identifier.
