# PHPQA Pipeline

PHPQA runs tools in a sequence designed to fail as quickly as possible, making it ideal for both local development and CI.

The steps are run in sequence. Any step that fails kills the whole process.

In local development, a failed step can be retried indefinitely until it passes. In CI, a failed step marks the CI process as failed.

The tools are organised into four phases. Code modification runs first (so later phases validate the final state of the code), followed by linting, static analysis, and testing. There is no point running static analysis on code that has not yet been auto-fixed, and no point running tests if the code has syntax errors.

Each tool is run in `bin/qa` using the [runTool](./../includes/functions.inc.bash#L20) function. This function handles the process of checking for a platform-specific tool and falling back to the generic tool.

## Hooks

There are two hooks in the pipeline which allow you to run project-specific tasks before the QA process commences and after it has successfully completed.

These are simple bash scripts that you place into your project's `qaConfig` folder called `hookPre.bash` and `hookPost.bash` respectively.

The script is sourced into the main QA process and so has access to all of the config variables as defined in the main script and overridden in your project config.

If you need something in this hook to fail the whole process:

```bash
exit 1;
```

### Suggested Use Cases

#### hookPre.bash

- Flushing and/or priming caches
- Building IDE helpers
- Updating composer dependencies

#### hookPost.bash

- Pushing code to CI
- Rebuilding example code
- Generating reports

## The Pipeline

### 1. Detection and Configuration

This step includes:

 - Platform detection (Symfony via `symfony.lock`, otherwise generic)
 - Detecting if Xdebug is available
 - [setPaths.inc.bash](./../includes/generic/setPaths.inc.bash): setting the paths to be checked and ignored
 - [setConfig.inc.bash](./../includes/generic/setConfig.inc.bash): setting the configuration
 - Checking for and applying project-specific overrides (`qaConfig/qaConfig.inc.bash`)

### 2. Preparation

This step includes:

 - [prepareDirectories.inc.bash](./../includes/generic/prepareDirectories.inc.bash): ensuring required directories exist
 - Tool install: `bin/qa` runs `scripts/tool-install.bash` unconditionally. `phive.xml` is a hard requirement (the script exits 1 if it is missing). In the default `install` mode it verifies the PHARs committed under `vendor-phar/` are present (PHIVE re-fetches only in the maintainer `update`/`--force` modes) Rector is delivered as the committed `vendor-phar/rector.phar` (verified like the other phars; maintainers rebuild it with `scripts/build-rector-phar.bash`).
 - Checking for and running your project's `hookPre.bash` script
 - Acquiring a run-level lock (`lock.inc.bash`) so concurrent `qa` runs cannot collide; a held lock aborts the run

### 3. QA Tools (Four Phases)

#### Phase 1: Code Modification
1. **Rector** -- Automated refactoring (safe functions, PHPUnit, PHP 8.4 upgrades)
2. **PHP CS Fixer** -- Code style fixing (runs as PHAR)

#### Phase 2: Linting and Validation
3. **PSR-4 Validation** -- Namespace/directory structure compliance
4. **Composer Checks** -- Diagnose, normalize, dump autoloader
5. **Package Type Declaration** -- Always-on: requires `composer.json` to declare a `type`
6. **Strict Types Enforcement** -- Ensures `declare(strict_types=1)`
7. **PHP Lint** -- Fast parallel syntax checking
8. **Composer Require Checker** -- Missing dependency detection (runs as PHAR)
9. **Markdown Links Checker** -- Link validation in documentation

#### Phase 3: Static Analysis
10. **Branch Name Policy** -- Always-on: enforces the PR branch-naming convention (runs first in this phase)
11. **PHPStan** -- Static analysis at level max (runs as PHAR)
12. **PHPArkitect** -- Architecture rules; on by default (`useArkitect=0` to disable, runs as PHAR)
13. **SensitiveParameter Usage** -- Always-on: fails if `#[\SensitiveParameter]` is used nowhere in `src/`

#### Phase 4: Testing
14. **PHPUnit** -- Unit and integration tests
15. **Infection** -- Mutation testing (optional, requires Xdebug, runs as PHAR)

To read about each tool in detail, see [PHPQA's suite of tools](./phpqa-tools.md).

### 4. Statistics and Post Hook

If all tests pass, you get some interesting stats about your codebase via PHPLoc.

Finally, the pipeline checks for a `hookPost.bash` in your project's `qaConfig` folder and runs it if found.

If there were retries of any of the tools, it is strongly suggested that you rerun the full pipeline before regarding it as passing.
