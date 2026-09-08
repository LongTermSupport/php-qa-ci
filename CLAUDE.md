# PHP-QA-CI Library Documentation

## Knowledge & Memory Policy (binding)

Persistent Claude memory is DISABLED for this project — never write to the
harness memory store (`~/.claude/projects/*/memory/`). ALL knowledge, memory
and context MUST be tracked in-repo, clean of secrets: durable operational
knowledge in `CLAUDE/*.md` (e.g. [CLAUDE/prepush-verification.md](CLAUDE/prepush-verification.md)
— the mandatory pre-push battery; pushing `php8.4` deploys to production),
programme/work records in `CLAUDE/Plan/`.

## Working on php-qa-ci from a consuming project's `vendor/` (dogfooding)

php-qa-ci is frequently installed **from source** into a consuming project, so
`vendor/lts/php-qa-ci/` is a real git checkout with its own `.git`. When a harness
change is needed while working inside the consumer, work on it in place — no separate
clone required:

- **Edit and commit in the vendored checkout.** Change the include/config/test under
  `vendor/lts/php-qa-ci/` and `git commit` there. Pushing follows the consuming
  project's policy for `lts/*` (typically: commit locally, a human pushes/releases).

- **Dogfood against real consumer code.** The vendored copy is the live copy the
  consumer's `vendor/bin/qa` runs from, so the consumer's next QA run exercises the
  change with no reinstall.

- **Also pass php-qa-ci's OWN battery before any push.** php-qa-ci ships its own
  `composer.json` + `composer.lock` + `qaConfig/`, so run its own QA against its own
  `src/`/`tests/`:

  ```bash
  cd vendor/lts/php-qa-ci
  composer install
  QA_READONLY=1 CI=true bin/qa        # full read-only battery (the real pre-push gate)
  CI=true bin/qa -t unit              # or a single tool while iterating
  ```

  A consumer's `bin/qa` validates the CONSUMER's code; the commands above validate
  php-qa-ci itself (its `Large` include-level tests, PHPStan, Rector/CS-Fixer
  dry-run). This is the battery [CLAUDE/prepush-verification.md](CLAUDE/prepush-verification.md)
  mandates before a `php8.4` push. The nested `vendor/lts/php-qa-ci/vendor/` from
  `composer install` is the package's own dev environment, isolated from the
  consumer's tree.

## Overview

PHP-QA-CI is a comprehensive quality assurance pipeline for PHP projects written in Bash. It orchestrates multiple PHP quality assurance tools in a carefully designed sequence to fail fast and provide rapid feedback.

## Architecture

### Core Components

1. **Main Script**: `bin/qa` - Entry point that orchestrates all tools
2. **Tool Runners**: Individual bash scripts in `includes/generic/` that run specific tools
3. **Configuration System**: Cascading configuration from defaults to project overrides
4. **Platform Detection**: Automatic detection of Symfony vs. generic platforms

### How It Works

When you run the qa script in your project:

1. The script detects your project root and platform type
2. Loads configuration from the shipped defaults, overridable per-project via `qaConfig/`
3. Runs tools from your project's bin directory (NOT from php-qa-ci's own vendor)
4. Executes tools in 4 phases in a specific order designed to modify code first, then validate

**Note on bin directory location**: The qa script is installed in the directory specified by the `bin-dir` config in your composer.json. By default this is `vendor/bin`, but it can be configured to any directory (e.g., `bin`). All examples in this documentation assume the default `vendor/bin` location.

## Pipeline Execution Order

### Preflight Phase (Configuration & Setup)

Before running any QA tools, the pipeline executes these preflight steps:

01. **Variable Initialization** (in `bin/qa`) - Core variables set before anything else:

    - `$qaDir` - The php-qa-ci library directory (where bin/qa lives)
    - `$projectRoot` - The project being tested
    - `$binDir` - The project's bin directory (usually vendor/bin)

02. **Platform Detection** (`detectPlatform`) - Identifies if project is Symfony (via `symfony.lock`) or generic

03. **Xdebug Check** - Determines if coverage/infection testing is available

04. **Set Paths** (`setPaths`) - Auto-detects and configures paths:

    - `testsDir` - Finds test directory
    - `srcDir` - Finds source directory
    - `binDir` - Finds bin directory (vendor/bin)
    - `pathsToCheck` - Array of paths to scan (defaults to tests + src)
    - `pathsToIgnore` - Array of paths to ignore

05. **Set Config** (`setConfig`) - Loads all configuration files in cascade order and defines:

    - `$projectConfigPath` - Project's qaConfig directory
    - `$varDir` - Project's var/qa directory
    - `$cacheDir` - Project's var/qa/cache directory
    - `$pharDir` - QA library's vendor-phar directory (for PHIVE-installed tools)
    - Various tool configuration paths

06. **Project Config Override** - Sources `qaConfig/qaConfig.inc.bash` if it exists

07. **Prepare Directories** (`prepareDirectories`) - Creates necessary directories:

    - `var/qa/` - Main QA output directory
    - `var/qa/cache/` - Tool cache directory
    - Adds .gitignore files to exclude generated content

08. **Tool Install** - Runs `scripts/tool-install.bash` unconditionally (`bin/qa`). `phive.xml` is a hard requirement: if it is missing the script prints an error and exits 1. In the default `install` mode it verifies the PHARs committed under `vendor-phar/` are present (PHIVE only re-fetches in the maintainer `update`/`--force` modes) Rector is delivered as the committed `vendor-phar/rector.phar` (verified alongside the other phars); it is NOT an isolated composer sub-project — maintainers rebuild it with `scripts/build-rector-phar.bash` (see [CLAUDE/Plan/00002-phar-vendored-rector](CLAUDE/Plan/00002-phar-vendored-rector/PLAN.md)).

09. **Pre-Hook** (`hookPre.bash`) - Runs project-specific pre-pipeline script if exists

10. **Locking** - Sources `includes/generic/lock.inc.bash` and acquires a run-level lock (`initLockSystem` / `acquireLock`) so concurrent `qa` invocations cannot collide. If another `qa` process already holds the lock, `acquireLock` aborts the run (`exit 1`) before any tool executes. Locking is run-level only — there are no per-tool timing hooks.

Only after all preflight steps complete does the actual tool execution begin.

### Main Tool Execution Phases

The pipeline runs tools in 4 distinct phases:

### Phase 1: Coding Standards Tools (can modify code)

1. **Rector** (`rector`) - Automated refactoring and code upgrades
2. **PHP CS Fixer** (`phpCsFixer`) - Code style fixing

### Phase 2: Linting Tools (validation only)

03. **PSR-4 Validation** (`psr4Validate`) - Validates namespace/directory structure
04. **Composer Checks** (`composerChecks`) - Runs composer diagnose and dumps autoloader
05. **Package Type Declaration** (`packageType`) - Always-on: requires `composer.json` to declare a `type` (see [docs/tools/packageType.md](docs/tools/packageType.md))
06. **Config Template Ignore-List Audit** (`configTemplateIgnoreList`) - Always-on self-check: every namespace-less `configDefaults/generic/` template must be covered by `psr4-validate-ignore-list.txt` (see [docs/tools/configTemplateIgnoreListCheck.md](docs/tools/configTemplateIgnoreListCheck.md))
07. **Infection Config Source Directories Check** (`infectionConfigSourceDirs`) - Always-on: infection.json's `source.directories` entries must resolve, relative to infection.json's own directory, to real directories (see [docs/tools/infectionConfigSourceDirs.md](docs/tools/infectionConfigSourceDirs.md))
08. **Strict Types Enforcement** (`phpStrictTypes`) - Ensures `declare(strict_types=1)` in all PHP files
09. **PHP Lint** (`phpLint`) - Fast parallel syntax checking
10. **Composer Require Checker** (`composerRequireChecker`) - Checks for missing dependencies
11. **Markdown Links Checker** (`markdownLinks`) - Validates links in markdown files

### Phase 3: Static Analysis Tools

12. **Branch Name Policy** (`branchNamePolicy`) - Runs first in this phase. Always-on: enforces the PR branch-naming convention (see [CLAUDE/branch-policy.md](CLAUDE/branch-policy.md))
13. **PHPStan ignoreErrors Justification** (`phpstanIgnoreJustification`) - Always-on: every `ignoreErrors` entry in `qaConfig/phpstan.neon` must carry a comment naming the hazard accepted and its scope (see [docs/tools/phpstan.md](docs/tools/phpstan.md#suppressing-errors))
14. **PHPStan** (`phpstan`) - Static analysis tool
15. **PHPArkitect** (`phpArkitect`) - Architecture rules (class naming, namespace layering, dependency direction). On by default; applies a generic-safe baseline and is composable/overridable per project. Opt out with `export useArkitect=0`. See the [PHPArkitect section in README.md](README.md#phparkitect-architecture-rules).
16. **SensitiveParameter Usage** (`sensitiveParameterUsage`) - Always-on security baseline: fails if `#[\SensitiveParameter]` is used nowhere in `src/`. Opt out per-project with `export useSensitiveParameterCheck=0`.

### Phase 4: Testing Tools

17. **PHPUnit** (`phpunit`) - Unit testing framework
18. **Infection** (`infection`) - Mutation testing (optional, requires `useInfection=1`)

### Post-Success Phase (After all tests pass)

After the "ALL TESTS PASSING" message:

19. **PHPLoc** (`phploc`) - Generates code statistics (lines of code, complexity, etc.)

    - This is informational only and cannot fail the pipeline
    - Provides metrics about code size and structure

20. **Post-Hook** (`hookPost.bash`) - Runs project-specific post-pipeline script if exists

    - Only runs if all previous tools passed
    - Common uses: generate reports, notifications, cleanup

### Final Steps

- **Retry Warning** - If any tools were retried during the run, displays a warning
- **Completion Message** - Shows hostname and completion status

## Configuration System

### Configuration Cascade

Config **files** are resolved by `configPath()` (`includes/functions.inc.bash`), a
3-level lookup — the first that exists wins:

1. **Project override** — `{project}/qaConfig/{relativePath}` (e.g. `qaConfig/phpstan.neon`)
2. **Platform default** — `configDefaults/{platform}/{relativePath}` — only `generic` ships,
   so on a Symfony project this rung exists only where an `includes/symfony/` override supplies
   it; otherwise the lookup falls through to generic
3. **Generic default** — `configDefaults/generic/{relativePath}` (e.g. `php_cs.php`,
   `phpstan.neon`)

There is no `configDefaults.inc.bash` and no per-platform `configDefaults/` directory beyond
`generic/`.

Config **variables** additionally cascade through bash: `setConfig` establishes the defaults,
then `{project}/qaConfig/qaConfig.inc.bash` is sourced afterwards and can override them.
`deriveDependentConfig()` re-applies coverage/infection gating and MSI floors *after* that
override is sourced, so a project's `phpUnitCoverage` / `useInfection` / `mutationScoreIndicator`
/ `coveredCodeMSI` overrides actually take effect. Per-tool script overrides live in
`{project}/qaConfig/tools/{toolName}.inc.bash` (resolved by `runTool`).

### Read-Only / CI Verification Mode

Whether the mutating tools (Rector, PHP CS Fixer, Strict Types) may WRITE is governed by
`qaReadOnly`, which is **orthogonal to `CI`**:

- **`CI`** controls interactivity only (no prompts, no retry loops). It is auto-enabled for
  Claude Code (`CLAUDECODE=1`) and non-TTY shells. `CI=true` does **not** make a run read-only.
- **`qaReadOnly`** controls writes, decided by `detectReadOnly()` (`includes/functions.inc.bash`):
  `QA_READONLY=1`/`true` → read-only; `QA_READONLY=0`/`false` → writable; else GitHub Actions
  (`GITHUB_ACTIONS=true`) → read-only; everything else (local TTY, Claude sessions, cron) →
  writable. In a read-only run the fixers run `--dry-run` and a pending change FAILS the gate
  instead of being applied. **To reproduce a CI failure locally, run `QA_READONLY=1 vendor/bin/qa`,
  not `CI=true vendor/bin/qa`.**

**Aggregate mode**: a read-only run also defaults to aggregate (non-fail-fast) mode
(`qaAggregate`), collecting every failing tool in one pass rather than stopping at the first.
Force it off with `QA_FAIL_FAST=1`. `--json` emits structured PHPStan output on fd 3 (with all
decoration redirected to stderr).

### Key Configuration Variables

```bash
# PHP binary path
phpBinPath=${PHP_QA_CI_PHP_EXECUTABLE:-$(which php)}

# Skip long-running tests
phpqaQuickTests=${phpqaQuickTests:-0}

# PHPUnit specific
phpUnitQuickTests=${phpUnitQuickTests:-0}
phpUnitCoverage=${phpUnitCoverage:-1}  # Coverage ON by default (needed for Infection)
phpUnitIterativeMode=${phpUnitIterativeMode:-0}

# Infection
useInfection=${useInfection:-1}  # Disabled if no xdebug/coverage

# CI mode
CI=${CI:-'false'}
```

### Memory Configuration

The pipeline provides a global memory limit that applies to all QA tools (default: 4G):

```bash
# Global memory limit for all QA tools
phpqaMemoryLimit=${phpqaMemoryLimit:-4G}
```

**How to Override**:

```bash
# In qaConfig/qaConfig.inc.bash (project-level):
export phpqaMemoryLimit=8G

# Or via environment variable:
phpqaMemoryLimit=2G vendor/bin/qa
```

## Platform Detection

The `detectPlatform` function checks for:

- **Symfony**: Presence of `symfony.lock` file
- **Generic**: Default for all other PHP projects (anything without `symfony.lock`)

There is no Laravel/`artisan` detection. Platform-specific tool overrides are loaded from
`includes/{platform}/` (currently only `includes/symfony/` ships any).

## Tool Runner System

The `runTool` function is the heart of the system:

1. Searches for tool implementations in this order:

   - `{project}/qaConfig/tools/{toolName}.inc.bash` (project override)
   - `includes/{platform}/{toolName}.inc.bash` (platform-specific)
   - `includes/generic/{toolName}.inc.bash` (generic default)

2. Sources the found script which runs the actual tool

3. In non-CI mode, allows retry on failure via `tryAgainOrAbort`

## PHP 8.4 Compatibility (php8.4 branch)

### Changes Made

- **Removed PHP_CodeSniffer** completely (was conflicting with PHP CS Fixer)
- **Updated PHP CS Fixer config** to use the `@PHP8x4Migration` ruleset
- **Added nullable type rules** for PHP 8.4's deprecation of implicit nullable parameters
- **PHP CS Fixer v3.84.0+** supports PHP 8.4 natively (no `PHP_CS_FIXER_IGNORE_ENV` needed)

### PHP 8.4 Specific Configuration

```php
// In configDefaults/generic/php_cs.php
'@PHP8x4Migration' => true,
'nullable_type_declaration_for_default_null_value' => true,
'nullable_type_declaration' => ['syntax' => 'question_mark'],
```

## Hook System

The pipeline provides multiple extension points for customization:

### Built-in Hooks

- `qaConfig/hookPre.bash` - Runs after preflight configuration but before main tools
- `qaConfig/hookPost.bash` - Runs after all tools complete successfully (after PHPLoc)

The post-hook only executes if the entire pipeline succeeds. This makes it ideal for:

- Generating coverage reports
- Sending notifications
- Updating documentation
- Deploying artifacts
- Custom metrics collection

### Per-Tool Hooks

Each tool can be completely overridden by creating:

- `qaConfig/tools/{toolName}.inc.bash` - Replaces the default tool implementation

This allows for arbitrary customization of any tool's behavior, including:

- Changing command-line arguments
- Adding pre/post processing
- Completely replacing the tool with custom logic
- Conditionally skipping tools based on custom criteria

Example custom tool hook:

```bash
# qaConfig/tools/phpstan.inc.bash
echo "Running custom PHPStan with project-specific rules"

# Pre-processing
composer dump-autoload

# Run PHPStan with custom config
phpNoXdebug -f "$binDir"/phpstan -- \
    analyse \
    --configuration="custom-phpstan.neon" \
    --level=8 \
    --memory-limit=2G \
    ${pathsToCheck[@]}

# Post-processing
echo "PHPStan complete, checking results..."
```

### Claude Code Hooks

PHP-QA-CI includes Claude Code hooks that provide guardrails and automation when using Claude Code for development:

**Included Hooks**:

- `php-qa-ci__auto-continue.py` - Reduces confirmation prompts (✅ recommended for all projects)
- `php-qa-ci__prevent-destructive-git.py` - Blocks commands that destroy uncommitted changes (✅ critical safety)
- `php-qa-ci__discourage-git-stash.py` - Discourages git stash with escape hatch (⚠️ optional)
- `php-qa-ci__block-plan-time-estimates.py` - Prevents time estimates in plan documents (⚠️ optional)
- `php-qa-ci__validate-claude-readme-content.py` - Ensures docs contain instructions, not logs (⚠️ optional)
- `php-qa-ci__enforce-markdown-organization.py` - Enforces doc organization (⚠️ optional, opinionated)

**Deployment**:

```bash
# Deploy all hooks, agents, and skills to your project
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

This will:

- Copy hooks to `.claude/hooks/`
- Make them executable
- Register them in `.claude/settings.json`

**Documentation**: See `.claude/hooks/README.md` for detailed hook documentation including:

- What each hook does
- When to use each hook
- Configuration options
- Testing and troubleshooting
- Hook architecture and format

**Recommendation**: Always deploy `php-qa-ci__auto-continue.py` and `php-qa-ci__prevent-destructive-git.py` by default. Evaluate others based on team standards.

**Migration**: Projects with old hook names (without `php-qa-ci__` prefix) will be automatically migrated during `composer install/update`. The deployment script updates `.claude/settings.json` to reference the new hook names.

## Managed Source

php-qa-ci can generate small PHP artefacts into a consumer's own production
namespace (a locked `<RootNs>\PhpQaCi\` tree), regenerated on every composer
install/update and drift-checked via `bin/managed-source check`. First artefact:
the `FactorySealedBy` attribute. See [CLAUDE/managed-source.md](CLAUDE/managed-source.md).

## Environment Requirements

- Linux/Unix environment (uses bash)
- PHP 8.4 or higher on this branch (`composer.json` requires `^8.4`; the `php8.4` branch targets PHP 8.4, while the separate `php8.3` branch supports PHP 8.3)
- Composer-installed project with php-qa-ci as a dependency
- Your project's composer.json must allow the `ergebnis/composer-normalize` plugin:
  ```json
  {
      "config": {
          "allow-plugins": {
              "ergebnis/composer-normalize": true
          }
      }
  }
  ```

### Custom PHP Executable

You can specify which PHP binary to use via the `PHP_QA_CI_PHP_EXECUTABLE` environment variable:

```bash
# Use specific PHP version (assuming default vendor/bin location)
PHP_QA_CI_PHP_EXECUTABLE=/usr/bin/php8.4 vendor/bin/qa

# Or export for the session
export PHP_QA_CI_PHP_EXECUTABLE=/usr/bin/php8.4
vendor/bin/qa
```

This is useful when:

- Running multiple PHP versions on the same system
- Testing compatibility across PHP versions
- Using custom PHP builds

## Common Customizations

### Override a Specific Tool

Create `qaConfig/tools/{toolName}.inc.bash`:

```bash
# Example: Custom PHPStan configuration
echo "Running custom PHPStan configuration"
phpNoXdebug -f "$binDir"/phpstan -- \
    analyse \
    --configuration="$phpstanConfigPath" \
    --level=5 \
    ${pathsToCheck[@]}
```

### Skip Specific Tools

In `qaConfig/qaConfig.inc.bash`:

```bash
# Skip infection testing
export useInfection=0
```

### Add Custom Paths

In `qaConfig/qaConfig.inc.bash`:

```bash
pathsToCheck+=("custom/path")
pathsToIgnore+=("vendor", "cache")
```

## Overriding Tool Configurations

To override any tool's default configuration:

1. **Copy the default config** from `vendor/lts/php-qa-ci/configDefaults/generic/` to your `qaConfig/` directory
2. **Update relative paths** - Change paths like `__DIR__` or relative references to work from your project root
3. **Customize as needed** - Modify rules, paths, and settings

Example for PHP CS Fixer:

```bash
# Copy default config
cp vendor/lts/php-qa-ci/configDefaults/generic/php_cs.php qaConfig/

# Edit qaConfig/php_cs.php
# Change: $finderPath = __DIR__ . '/php_cs_finder.php';
# To:     $finderPath = __DIR__ . '/../vendor/lts/php-qa-ci/configDefaults/generic/php_cs_finder.php';
```

**Warning**: Always check and update relative paths when copying configs!

## Tools Reference

### Rector

- **Purpose**: Automated refactoring and code upgrades
- **Tool**: [@includes/generic/rector.inc.bash](includes/generic/rector.inc.bash)
- **Default**: [@configDefaults/generic/rector-safe.php](configDefaults/generic/rector-safe.php)
- **How it works**: Parses PHP code into AST, applies transformation rules, writes back modified code
- **Key features**:
  - Upgrades code to newer PHP versions
  - Applies coding standards automatically
  - Can be configured with custom rules

### PHP CS Fixer

- **Purpose**: Automatically fixes code style issues
- **Tool**: [@includes/generic/phpCsFixer.inc.bash](includes/generic/phpCsFixer.inc.bash)
- **Default**: [@configDefaults/generic/php_cs.php](configDefaults/generic/php_cs.php)
- **Finder**: [@configDefaults/generic/php_cs_finder.php](configDefaults/generic/php_cs_finder.php)
- **How it works**: Tokenizes PHP files, applies formatting rules, writes back formatted code
- **Key features**:
  - Supports PSR-12, Symfony, and custom standards
  - Can run risky rules that change code behavior
  - Highly configurable with 200+ rules

### PSR-4 Validate

- **Purpose**: Ensures namespace/directory structure compliance with PSR-4
- **Tool**: [@includes/generic/psr4Validate.inc.bash](includes/generic/psr4Validate.inc.bash)
- **Binary**: `bin/psr4-validate`
- **Ignore list**: [@configDefaults/generic/psr4-validate-ignore-list.txt](configDefaults/generic/psr4-validate-ignore-list.txt)
- **How it works**: Reads composer.json autoload definitions, checks each PHP file's namespace matches its directory location
- **Key features**:
  - Validates both psr-4 and psr-0 autoloading
  - Supports ignore patterns for legacy code

### Composer Checks

- **Purpose**: Validates composer configuration and dependencies
- **Tool**: [@includes/generic/composerChecks.inc.bash](includes/generic/composerChecks.inc.bash)
- **Requirements**:
  - `ergebnis/composer-normalize` plugin must be allowed in YOUR PROJECT's composer.json
- **How it works**:
  - Checks if `ergebnis/composer-normalize` plugin is allowed
  - Runs `composer diagnose` to check for issues
  - Runs `composer normalize` to normalize composer.json
  - Runs `composer dump-autoload` to ensure autoloading works
- **Required in your project's composer.json**:
  ```json
  {
      "config": {
          "allow-plugins": {
              "ergebnis/composer-normalize": true
          }
      }
  }
  ```
  After adding, run: `composer update nothing`

### Package Type Declaration

- **Purpose**: Requires `composer.json` to declare an explicit `type` (Composer silently defaults an omitted `type` to `library`, which changes how the API-surface PHPStan rules behave)
- **Tool**: [@includes/generic/packageType.inc.bash](includes/generic/packageType.inc.bash)
- **Binary**: `bin/package-type-check`
- **When it runs**: Always-on, Phase 2, immediately after Composer Checks
- **Details**: [docs/tools/packageType.md](docs/tools/packageType.md)

### Config Template Ignore-List Audit

- **Tool**: [@includes/generic/configTemplateIgnoreList.inc.bash](includes/generic/configTemplateIgnoreList.inc.bash)
- **Purpose**: every namespace-less template under `configDefaults/generic/` is covered by `psr4-validate-ignore-list.txt`, so the documented copy-override never fails `psr4Validate`
- **Identifier**: `phpqaci.configTemplateIgnoreList`
- **Details**: [docs/tools/configTemplateIgnoreListCheck.md](docs/tools/configTemplateIgnoreListCheck.md)

### PHP Strict Types

- **Purpose**: Ensures all PHP files have `declare(strict_types=1)`
- **Tool**: [@includes/generic/phpStrictTypes.inc.bash](includes/generic/phpStrictTypes.inc.bash)
- **How it works**: Scans `.php`/`.phtml` files under the checked paths for a missing declaration
- **Read-only run**: reports every offending file and fails
- **Writable run**: adds the declaration to the opening `<?php` tag automatically and reports each fixed file; a file with no opening tag fails the gate

### PHP Lint

- **Purpose**: Fast parallel syntax checking
- **Tool**: [@includes/generic/phpLint.inc.bash](includes/generic/phpLint.inc.bash)
- **How it works**: Uses PHP's built-in `-l` flag to check syntax, runs in parallel for speed
- **Key features**:
  - Much faster than full parsing
  - Catches parse errors before running other tools

### Composer Require Checker

- **Purpose**: Ensures all code dependencies are explicitly declared in composer.json
- **Tool**: [@includes/generic/composerRequireChecker.inc.bash](includes/generic/composerRequireChecker.inc.bash)
- **Default**: [@configDefaults/generic/composerRequireChecker.json](configDefaults/generic/composerRequireChecker.json)
- **How it works**:
  - Scans all PHP files for symbols (classes, functions, constants)
  - Checks if each symbol's package is explicitly required in composer.json
  - Fails if using transitive dependencies without declaring them
- **Key principles**:
  - **Explicit is better than implicit** - If you use it, declare it
  - **Don't rely on transitive dependencies** - They might be removed
  - Example: If you use `Symfony\Component\HttpKernel\Kernel`, you must require `symfony/http-kernel` even if it's installed via `symfony/framework-bundle`
- **Common issues**:
  - Using Symfony components without explicit require
  - Safe functions from `thecodingmachine/safe` after Rector conversion
  - PSR interfaces without requiring the PSR package

### Markdown Links Checker

- **Purpose**: Validates links in markdown documentation
- **Tool**: [@includes/generic/markdownLinks.inc.bash](includes/generic/markdownLinks.inc.bash)
- **Binary**: `bin/mdlinks`
- **How it works**: Parses markdown files, checks internal file links and external URLs
- **Scope**: README.md and all files in docs/

### Branch Name Policy

- **Purpose**: Enforces the PR branch-naming convention (a PR branch must use an allowed prefix — `feature/`, `bugfix/`, `chore/`, `hotfix/` — never `plan/*`); the repo's detected default branch is exempt
- **Tool**: [@includes/generic/branchNamePolicy.inc.bash](includes/generic/branchNamePolicy.inc.bash)
- **When it runs**: Always-on, first tool in Phase 3 (Static Analysis)
- **Fallback**: on default-branch detection failure it warns and requires explicit config in `qaConfig/branchNamePolicy.yaml` — there is no hardcoded default-branch guess list
- **Details**: [CLAUDE/branch-policy.md](CLAUDE/branch-policy.md)

### PHPStan

- **Purpose**: Static analysis for finding bugs without running code
- **Tool**: [@includes/generic/phpstan.inc.bash](includes/generic/phpstan.inc.bash)
- **Default**: [@configDefaults/generic/phpstan.neon](configDefaults/generic/phpstan.neon)
- **How it works**: Builds understanding of entire codebase, performs type inference and checks
- **Key features**:
  - Configurable levels 0-9 (max)
  - Extensible with custom rules
  - Understands PHPDoc annotations

### PHPArkitect

- **Purpose**: Enforce architectural/structural rules — class-naming conventions, namespace layering, dependency direction — that PHPStan expresses awkwardly
- **Tool**: [@includes/generic/phpArkitect.inc.bash](includes/generic/phpArkitect.inc.bash)
- **PHAR**: `vendor-phar/phparkitect.phar` (PHIVE, key `47CD54B6398FE21B3709D0A4D9C905CED1932CA2`, short id `D9C905CED1932CA2`)
- **Entry config (default)**: [@configDefaults/generic/phparkitect.php](configDefaults/generic/phparkitect.php) — applies the default tier to the detected source dir when a project has no `qaConfig/phparkitect.php`
- **Rule tiers**: `phparkitect-rules-default.php` (on by default), `phparkitect-rules-optional.php` + `phparkitect-rules-optional-symfony.php` (opt-in) under [@configDefaults/generic](configDefaults/generic)
- **Project template**: [@templates/qaConfig-phparkitect.php](templates/qaConfig-phparkitect.php)
- **How it works**: parses each class into an AST and matches expressions (naming, dependencies); rules and the paths to scan are defined inside the config (so `-p` does not apply). The pipeline passes `--autoload` and exports the tier paths + detected `srcDir` as env vars
- **Where a rule belongs (PHPArkitect vs PHPStan)**: arkitect by default for structural rules; upgrade to a PHPStan rule only for finer-grained / method-level / semantic detection arkitect cannot express. **Never enforce one convention in both engines** — migrate, don't duplicate (SSoT). Full decision guide: [README.md "Where does a rule belong"](README.md#where-does-a-rule-belong--phparkitect-or-phpstan)
- **Excluding generated code at any path**: the default config always excludes a `Generated` dir; for generated code elsewhere declare `arkitectExcludePaths+=("Some/Path")` in `qaConfig/qaConfig.inc.bash` (no config copy needed — matched via `Glob::toRegex` against the `src/`-relative path; exported as `PHPQACI_ARKITECT_EXCLUDE_PATHS`). Prefer this over `useArkitect=0`, which drops rules for the whole project. See [README.md "Excluding generated code"](README.md#excluding-generated-code-at-any-path)
- **Full usage** (tiers, extend/replace/customise, disable): see the [PHPArkitect section in README.md](README.md#phparkitect-architecture-rules)

### PHPUnit

- **Purpose**: Unit testing framework
- **Tool**: [@includes/generic/phpunit.inc.bash](includes/generic/phpunit.inc.bash)
- **Default**: [@configDefaults/generic/phpunit.xml](configDefaults/generic/phpunit.xml)
- **How it works**: Discovers and runs test methods, reports results
- **Key features**:
  - Coverage analysis with Xdebug
  - Parallel execution support
  - Multiple output formats

### Infection

- **Purpose**: Mutation testing to verify test quality
- **Tool**: [@includes/generic/infection.inc.bash](includes/generic/infection.inc.bash)
- **Default**: [@configDefaults/generic/infection.json](configDefaults/generic/infection.json)
- **How it works**: Modifies source code (mutations), runs tests to see if they catch the changes
- **Requirements**: Xdebug and code coverage enabled
- **Key metrics**:
  - MSI (Mutation Score Indicator)
  - Covered Code MSI

### PHPLoc

- **Purpose**: Measure project size and complexity
- **Tool**: [@includes/generic/phploc.inc.bash](includes/generic/phploc.inc.bash)
- **How it works**: Parses PHP files and counts lines, classes, methods, complexity
- **Output**: Statistics only, cannot fail the pipeline

## Important Notes

1. **Tools modify code in Phase 1** - This is why Rector and PHP CS Fixer run first
2. **Project's vendor/bin is used** - Not php-qa-ci's internal vendor directory
3. **Configuration is highly flexible** - Almost every aspect can be overridden
4. **Platform detection is automatic** - But can be overridden if needed
5. **Fail-fast design** - Pipeline stops on the first tool failure (except in interactive retry mode, and except in read-only aggregate mode, where every failing tool is collected and reported together — see "Read-Only / CI Verification Mode")

## Design Philosophy: Standardized Configuration

### The QA Pipeline is NOT a Tool Proxy

**CRITICAL UNDERSTANDING**: The PHP-QA-CI pipeline is designed to enforce consistent, standardized tool configurations across projects. It is **NOT** intended to be a flexible proxy that passes arbitrary arguments to underlying tools.

#### What the QA Pipeline IS For:

- ✅ **Enforcing consistent configurations** - Same PHPStan level, same CS Fixer rules across projects
- ✅ **Orchestrating tool execution** - Running tools in the correct order with proper dependencies
- ✅ **Managing tool dependencies** - Handling PHIVE installs, cache directories, etc.
- ✅ **Path specification** - Running tools against specific directories: `vendor/bin/qa -t stan -p src/Domain`
- ✅ **Standardized environments** - Consistent Xdebug settings, memory limits, etc.

#### What the QA Pipeline is NOT For:

- ❌ **Arbitrary tool flags** - Don't expect `vendor/bin/qa -t stan --help` to work
- ❌ **Custom tool arguments** - The pipeline controls all tool arguments for consistency
- ❌ **Tool-specific customization per run** - Use project config files instead
- ❌ **Direct tool replacement** - Not a substitute for running tools directly when needed

### Why This Design?

1. **Consistency** - Every project using the QA pipeline runs tools with the same standards
2. **Maintainability** - Tool configurations are managed centrally, not scattered across command invocations
3. **Reliability** - No chance of accidentally running with wrong flags or missing dependencies
4. **Standardization** - Teams can depend on consistent tool behavior across projects

### When You Need Flexibility

If you need to run a tool with custom arguments that the QA pipeline doesn't support:

1. **For configuration changes**: Create/modify project config files in `qaConfig/`
2. **For one-off runs**: Call the tool binary directly: `vendor/bin/phpstan analyse --help`
3. **For custom workflows**: Create your own wrapper scripts that call tools directly

### Path Specification Examples

The QA pipeline DOES support specifying which paths to scan:

```bash
# Run PHPStan only on src directory
vendor/bin/qa -t stan -p src

# Run PHP CS Fixer only on Domain namespace
vendor/bin/qa -t fixer -p src/Domain

# Run full pipeline on specific path
vendor/bin/qa -p tests/Unit
```

This maintains consistency while allowing targeted execution.

### Integration with Development Tools

Development scripts (like docker.bash) should:

- ✅ Use `vendor/bin/qa -t toolname` for standardized runs
- ✅ Support path specification: `-p src/specific/path`
- ❌ Try to pass arbitrary tool flags through the QA pipeline
- ✅ Fall back to direct tool execution when custom flags are actually needed

**Remember**: The QA pipeline's strength is its consistency, not its flexibility. Use it for what it's designed for.

<hooksdaemon>
<!-- Auto-generated by hooks daemon on restart. Do not edit this section — changes will be overwritten. -->

## Hooks Daemon — Active Handler Guidance

The handlers listed below are active in this project. Read this section to avoid triggering unnecessary blocks.

**When a tool is blocked by a handler, do not stop working.** Read the block reason, modify your approach, and continue with your task.

**A file written through Bash is not seen by the content guards that run BEFORE the write.** The PreToolUse handlers below that inspect what a file CONTAINS, or where it lives, key on the `Write` and `Edit` tools — so a `>`, `>>`, `tee` or a `cat <<EOF` heredoc reaches disk unexamined by them: no block, no advisory, no record. **A Bash write that drew no complaint is NOT a write that passed those checks** — use `Write`/`Edit` for file content and they apply.

**The LINTERS are the exception, and they DENY.** `lint_on_edit` and `validate_eslint_on_write` do run on a file a Bash command AUTHORS — a redirect, `tee`, a heredoc — so unparseable Python or failing TypeScript is reported however it reached disk. The write has already landed, so the denial is a failure report to repair with `Edit`, not a rollback. A file the command merely RELOCATES (`cp`, `mv`, `install`, `dd`) is never linted: those bytes were already on disk, so blaming the copy would report a defect the command did not introduce.

The handlers that judge a Bash COMMAND — destructive git, `sed`, pipes, permissions, `curl | sh` — are unaffected and still cover you.

Full detail on any rule: `bin/hooks-daemon explain-rule <ID>`.

## All other enforced rules

<!-- handler: require-absolute-paths -->

<!-- handler: block-ancestry-severing-merge -->

<!-- handler: block-artefact-publishing -->

<!-- handler: bash-safe-mode -->

<!-- handler: block-comment-changelog -->

<!-- handler: block-comment-size -->

<!-- handler: block-curl-pipe-shell -->

<!-- handler: daemon-location-guard -->

<!-- handler: block-dangerous-permissions -->

<!-- handler: prevent-destructive-git -->

<!-- handler: docs-qa-commit-gate -->

<!-- handler: docs-qa-edit -->

<!-- handler: error-hiding-blocker -->

<!-- handler: flaggable-content-channel-guard -->

<!-- handler: require-gh-issue-comments -->

<!-- handler: require-gh-pr-comments -->

<!-- handler: block-git-message-backtick -->

<!-- handler: block-git-stash -->

<!-- handler: github_auto_close_keywords -->

<!-- handler: lock-file-edit-blocker -->

<!-- handler: enforce-lsp-usage -->

<!-- handler: enforce-markdown-organization -->

<!-- handler: enforce-npm-commands -->

<!-- handler: block-pip-break-system -->

<!-- handler: pipe-blocker -->

<!-- handler: plan-number-helper -->

<!-- handler: plan-qa-commit-gate -->

<!-- handler: plan-qa-edit -->

<!-- handler: block-plan-time-estimates -->

<!-- handler: enforce-project-containment -->

<!-- handler: qa-suppression-blocker -->

<!-- handler: quarantine-artefact-read-guard -->

<!-- handler: remote-docs-commit-gate -->

<!-- handler: remote-docs-provenance -->

<!-- handler: remote-docs-routing -->

<!-- handler: root-recursion-guard -->

<!-- handler: block-secret-file-read -->

<!-- handler: block-security-antipatterns -->

<!-- handler: block-sed-command -->

<!-- handler: block-sensitive-content -->

<!-- handler: staged-lint-gate -->

<!-- handler: block-sudo-pip -->

<!-- handler: enforce-tdd -->

<!-- handler: validate-instruction-content -->

<!-- handler: verification-result-gate -->

<!-- handler: prevent-worktree-file-copying -->

<!-- handler: block-unread-overwrite -->

<!-- handler: lint-on-edit -->

<!-- handler: failsafe-cron-blockage-suppressor -->

<!-- handler: auto-continue-stop -->

| ID                                 | Blocked                                                                                                            | Why                                                                                                                                                                                               | Fix                                                                                         |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| R-ABSOLUTE-PATH-REQUIRED           | `Read`/`Write`/`Edit` file_path requires absolute path                                                             | Ambiguous about the current working directory and can target the wrong file                                                                                                                       | Use an absolute path starting with /                                                        |
| R-GIT-MERGE-SQUASH                 | `git merge --squash`                                                                                               | Severs ancestry -- git branch -d refuses the branch forever                                                                                                                                       | Use git merge --no-ff instead                                                               |
| R-GH-PR-MERGE-SQUASH               | `gh pr merge --squash`                                                                                             | Severs ancestry -- git branch -d refuses the branch forever                                                                                                                                       | Use gh pr merge --merge instead                                                             |
| R-GH-PR-MERGE-REBASE               | `gh pr merge --rebase`                                                                                             | Severs ancestry -- git branch -d refuses the branch forever                                                                                                                                       | Use gh pr merge --merge instead                                                             |
| R-ARTIFACT-PUBLISH                 | publishing an artefact via the `Artifact` tool                                                                     | The page lives OUTSIDE the project and the repository cannot audit or retract it                                                                                                                  | Write the file locally and tell the user its path, or ask a human to publish                |
| R-BASH-SAFE-MODE-PRELUDE-MISSING   | a sequenced Bash invocation with no `set` safety prelude                                                           | Errors in earlier statements can be silently ignored                                                                                                                                              | Add `set -euo pipefail` at the top, or gate explicitly with `&&`/\`                         |
| R-COMMENT-CHANGELOG                | changelog narrative in a code comment                                                                              | A comment describes CURRENT STATE; history belongs elsewhere                                                                                                                                      | Move it to git, a changelog file, or the plan's JOURNAL/                                    |
| R-COMMENT-SIZE                     | a comment growing past its configured size limit                                                                   | Comments should describe current state, not accumulate                                                                                                                                            | Shorten the comment, or declare MUST_EXCEED_COMMENT_SIZE_BECAUSE                            |
| R-CURL-PIPE-SHELL                  | \`curl                                                                                                             | wget ...                                                                                                                                                                                          | bash                                                                                        |
| R-DAEMON-DIR-CD                    | `cd` into `.claude/hooks-daemon/`                                                                                  | Daemon CLI commands must be run from PROJECT ROOT, causing path confusion otherwise                                                                                                               | Run daemon commands from project root, e.g. `bin/hooks-daemon status`                       |
| R-CHMOD-WORLD-WRITABLE             | `chmod 777`/`chmod a+w`/`chmod o+w`                                                                                | Allows anyone to read, write, and execute, bypassing all file permission security                                                                                                                 | Use least-privilege permissions instead (755/644/600)                                       |
| R-GIT-RESET-HARD                   | `git reset --hard`                                                                                                 | Permanently destroys all uncommitted changes                                                                                                                                                      | Ask the user to run it manually                                                             |
| R-GIT-CLEAN-FORCE                  | `git clean -f`                                                                                                     | Permanently deletes untracked files                                                                                                                                                               | Ask the user to run it manually                                                             |
| R-GIT-CHECKOUT-DISCARD             | `git checkout -- <file>` / `git checkout .`                                                                        | Discards local changes to file(s) permanently                                                                                                                                                     | Ask the user to run it manually                                                             |
| R-GIT-RESTORE                      | `git restore <file>`                                                                                               | Discards local changes to files permanently (`--staged`/`-S` is allowed)                                                                                                                          | Ask the user to run it manually                                                             |
| R-GIT-STASH-DROP                   | `git stash drop`                                                                                                   | Permanently destroys a stashed change                                                                                                                                                             | Ask the user to run it manually                                                             |
| R-GIT-STASH-CLEAR                  | `git stash clear`                                                                                                  | Permanently destroys all stashed changes                                                                                                                                                          | Ask the user to run it manually                                                             |
| R-GIT-PUSH-FORCE                   | `git push --force`                                                                                                 | Can overwrite remote history and destroy team members' work                                                                                                                                       | Ask the user to run it manually, or coordinate and use `--force-with-lease`                 |
| R-GIT-BRANCH-FORCE-DELETE          | `git branch -D`                                                                                                    | Force-deletes a branch without checking if it has been merged                                                                                                                                     | Use `git branch -d` first (refuses unmerged branches); ask the user for -D                  |
| R-GIT-COMMIT-AMEND                 | `git commit --amend`                                                                                               | Rewrites the previous commit, creating messy history and potential data loss                                                                                                                      | Create a new commit instead                                                                 |
| R-DOCS-QA-COMMIT                   | a git commit violates a block-level docs QA staged-tree check                                                      | Most doc rot that matters at commit time is cross-file drift a single-file edit hook cannot see                                                                                                   | Fix the content per each finding's remediation below and amend the commit                   |
| R-DOCS-QA-EDIT                     | a documentation Write/Edit violates a block-level docs QA check                                                    | A finding only denies the write when it is BLOCK severity AND the resolved mode for that check is block                                                                                           | Fix the content per each finding's remediation below and retry                              |
| R-ERROR-HIDING                     | an error-hiding pattern (bare except,                                                                              |                                                                                                                                                                                                   | true, empty catch, result, _ := ..., ...)                                                   |
| R-FLAGGABLE-CONTENT-CHANNEL        | a content-revealing git/grep command shape over a flaggable path                                                   | It would reveal flaggable content inside routine command output, with no deliberate Read at all                                                                                                   | Delegate the WHOLE review to the quarantine subagent instead                                |
| R-GH-ISSUE-VIEW-NO-COMMENTS        | `gh issue view` without `--comments`                                                                               | Issue comments contain critical context, clarifications and updates not in the issue body                                                                                                         | Add --comments, or include comments in --json fields                                        |
| R-GH-PR-VIEW-NO-COMMENTS           | `gh pr view` without `--comments`                                                                                  | PR comments contain review feedback and discussion context not in the PR body                                                                                                                     | Add --comments, or include comments in --json fields                                        |
| R-GIT-MESSAGE-BACKTICK             | an unescaped backtick in a double-quoted git commit/tag message                                                    | Bash performs command substitution inside double quotes -- the span is EXECUTED, not quoted                                                                                                       | Use single quotes, or git commit -F <file>                                                  |
| R-GIT-STASH-PUSH                   | `git stash` / `git stash push` / `git stash save`                                                                  | Stashes get forgotten, lost, and block git pull                                                                                                                                                   | Use git commit instead — WIP commits are fine                                               |
| R-GH-AUTO-CLOSE-KEYWORD            | a GitHub closing keyword + issue reference in a git/gh message                                                     | Auto-closes the referenced issue/PR the moment the commit reaches the default branch, and cannot be disabled repository-side                                                                      | Use a non-closing reference instead, e.g. Addresses #123                                    |
| R-LOCK-FILE-EDIT                   | Direct `Write`/`Edit` of a package manager lock file                                                               | Lock files are generated artifacts; manual edits create checksum mismatches and broken dependency graphs                                                                                          | Use the package manager commands instead (e.g. `npm install`, `cargo update`)               |
| R-LSP-SYMBOL-LOOKUP                | a symbol-like Grep/Bash grep lookup                                                                                | LSP tools give semantic ~50ms code intelligence; grep is slow and imprecise                                                                                                                       | Use goToDefinition/findReferences/workspaceSymbol/hover/documentSymbol instead              |
| R-MARKDOWN-WRONG-LOCATION          | MARKDOWN FILE IN WRONG LOCATION — a new `.md` file written to an unrecognised location                             | Markdown files must follow project organization rules                                                                                                                                             | Move it into an allowed location, or configure `extra_allowed_markdown_paths`               |
| R-MARKDOWN-UNTRACKED-MEMORY        | UNTRACKED CLAUDE MEMORY IS DISABLED FOR THIS PROJECT — a write to `~/.claude/projects/*/memory/*.md`               | That knowledge is per-checkout, un-reviewed, and invisible to teammates — it drifts from the repo and bypasses code review                                                                        | Document it in tracked project docs instead (CLAUDE.md, .claude/rules/\*.md, docs/)         |
| R-MARKDOWN-PLAN-SYNC               | a `.claude/settings.json` `plansDirectory` out of sync with the daemon's plan_workflow config                      | Plan workflow requires plansDirectory to match daemon config to redirect writes correctly                                                                                                         | Fix `.claude/settings.json`'s `plansDirectory` key, then restart your session               |
| R-NPM-PIPED-COMMAND                | a piped `npm run`/`npx` command                                                                                    | Piping npm/npx commands is pointless — llm: cache files hold the full data                                                                                                                        | Run the plain command, then query the cache file with jq                                    |
| R-NPM-NON-LLM-COMMAND              | a raw `npm run`/`npx` command when llm: wrappers exist                                                             | llm: commands provide LLM-friendly, machine-readable output                                                                                                                                       | Use the project's `npm run llm:*` equivalent instead                                        |
| R-PIP-BREAK-SYSTEM-PACKAGES        | `pip install --break-system-packages`                                                                              | Bypasses PEP 668 protection and can corrupt the system Python installation                                                                                                                        | Use a virtual environment or `pip install --user` instead                                   |
| R-PIPE-TO-TAIL                     | \`                                                                                                                 | tail\`                                                                                                                                                                                            | Truncates output and causes information loss                                                |
| R-PIPE-TO-HEAD                     | \`                                                                                                                 | head\`                                                                                                                                                                                            | Truncates output and causes information loss                                                |
| R-PLAN-NUMBER-DISCOVERY            | a bash discovery scan (ls/find/sort+tail) for the next plan number                                                 | Misses subdirectories like Completed/ and disagrees across branches                                                                                                                               | Use the printed next plan number, or the git counter directly                               |
| R-PLAN-FOLDER-MKDIR                | `mkdir <plan-dir>/NNNNN-name` (hand-creating a plan folder)                                                        | Claims a plan number the moment the folder appears, but nothing records the claim until PLAN.md is written                                                                                        | Use the mkplan.bash scaffolder instead                                                      |
| R-PLAN-QA-COMMIT                   | a git commit violates a block-level plan QA cross-file invariant                                                   | Most plan rot is cross-file and a single-file edit hook cannot see it                                                                                                                             | Amend the commit to also stage what each finding's remediation names below                  |
| R-PLAN-QA-EDIT                     | a PLAN.md/README.md Write/Edit violates a block-level plan QA check                                                | Plan QA linting catches issues you can fix immediately, before they reach commit                                                                                                                  | Fix the content per each finding's remediation below and retry                              |
| R-PLAN-TIME-ESTIMATE               | Time estimates not allowed in plan documents                                                                       | Time estimates in plans create false expectations and pressure                                                                                                                                    | Break work into concrete tasks and implementation steps; let the user decide scheduling     |
| R-WRITE-OUTSIDE-PROJECT-ROOT       | a write whose target is outside the repository root                                                                | Outside the repo nothing is version-controlled, reviewed or durable — a container's temp directory is wiped on restart, and every other path rule is scoped to the repo so none of them judges it | Write it inside the repository — `untracked/scratch/` is the scratch location               |
| R-QA-SUPPRESSION                   | a QA suppression directive (noqa, type: ignore, eslint-disable, ...)                                               | Suppression comments hide real problems and create technical debt                                                                                                                                 | Fix the underlying issue; do not suppress the warning                                       |
| R-QUARANTINE-ARTEFACT-READ         | reading a quarantined `*-opus-security-DETAIL*` artefact into the coordinator                                      | A DETAIL artefact holds raw flaggable substance meant for a human or another quarantine agent only                                                                                                | Read the paired `*-opus-security-SUMMARY*` artefact instead                                 |
| R-REMOTE-DOCS-STAGED-PROVENANCE    | a commit staging a remote-docs file without valid provenance frontmatter                                           | An unattributed vendored document that reaches history needs a rewrite to remove, and cannot be refreshed, dated or trusted meanwhile                                                             | Capture with `hooks-daemon remote-docs add <url>` and re-stage                              |
| R-REMOTE-DOCS-PROVENANCE           | a write into the remote-docs tree without valid provenance frontmatter                                             | A vendored document with no recorded source is indistinguishable from something we wrote ourselves, and cannot be refreshed, dated or trusted                                                     | Capture with `hooks-daemon remote-docs add <url>` instead of hand-authoring                 |
| R-REMOTE-DOCS-VENDORED-COPY        | a WebFetch of a URL this project already holds a fresh vendored copy of                                            | The local copy is faster, costs no network round trip, and is the corpus the remote-docs tree exists to build                                                                                     | Read the local path named in the message, or refresh it if you need newer content           |
| R-ROOT-RECURSION-CATASTROPHIC      | `grep -r`/`find`/`rg`/... rooted at `/`, `/proc`, `/sys`, `/home`, `/root`, `~`, `$HOME`                           | Walks the entire filesystem and can pin every CPU core for hours                                                                                                                                  | Scope the search to the project (e.g. `rg -l "pattern" .`)                                  |
| R-SECRET-READ                      | Read/Write/Edit/NotebookEdit/Grep targeting a protected path                                                       | The file's contents must NEVER be read into context by any route — not Read, not Bash, not an interpreter one-liner, not a copy                                                                   | Use `bin/hooks-daemon secret-meta <path>` for metadata, or ask the user                     |
| R-SECRET-BASH-MENTION              | a Bash command whose text mentions a protected path                                                                | The file's contents must NEVER be read into context by any route — not Read, not Bash, not an interpreter one-liner, not a copy                                                                   | Use `bin/hooks-daemon secret-meta <path>` for metadata, or ask the user                     |
| R-SECRET-SCRIPT-AUTHOR             | a script authored via Write/Edit whose content references a protected path                                         | The file's contents must NEVER be read into context by any route — not Read, not Bash, not an interpreter one-liner, not a copy                                                                   | Use `bin/hooks-daemon secret-meta <path>` for metadata, or ask the user                     |
| R-SEC-CODE-INJECTION               | `eval`, `exec`, `new Function`, `__import__`, `instance_eval`, `yaml.load`                                         | Dynamic execution of a string as code                                                                                                                                                             | Avoid dynamic code execution; use safe parsing/import alternatives                          |
| R-SEC-CMD-INJECTION                | `os.system`, `subprocess(..., shell=True)`, `shell_exec`, `proc_open`, `Runtime.exec`, `Process.Start`, `IO.popen` | Shell command construction from untrusted input enables command injection                                                                                                                         | Use argument-list APIs (no shell=True) instead of shell string concatenation                |
| R-SEC-DESERIALISATION              | `pickle.load`, `Marshal.load`, `unserialize`, `ObjectInputStream`, `XMLDecoder`, `BinaryFormatter`                 | Deserialising untrusted data can execute arbitrary code                                                                                                                                           | Use a safe serialisation format (e.g. JSON) instead                                         |
| R-SEC-XSS                          | `innerHTML`, `dangerouslySetInnerHTML`, `document.write`, `template.HTML`/`JS`/`URL`                               | Injects unescaped content into the DOM/output, enabling XSS                                                                                                                                       | Use the framework's safe templating/escaping APIs                                           |
| R-SEC-HARDCODED-CREDS              | AWS access keys, GitHub tokens, Stripe keys, private key blocks                                                    | Hardcoded credentials leak via source control history and code review                                                                                                                             | Use environment variables, never hardcode credentials                                       |
| R-SEC-UNSAFE-MEMORY                | Rust `from_raw_parts`, `transmute`                                                                                 | Bypasses Rust's memory/type safety guarantees                                                                                                                                                     | Use safe conversions (`as`, `From`/`Into`) or validated slice operations                    |
| R-SED-FILE-MODIFICATION            | `sed`                                                                                                              | Claude gets sed syntax wrong regularly and a single error can destroy hundreds of files                                                                                                           | Use the Edit tool (or parallel Haiku agents with Edit for bulk changes)                     |
| R-SENSITIVE-PUBLIC-PATTERN         | content matching a configured public pattern                                                                       | The pattern is a named, safe-to-disclose signal (a path, a placeholder, profanity, ...)                                                                                                           | Remove or replace the matched text before retrying                                          |
| R-SENSITIVE-SECRET-TERM            | content matching a configured blocked term                                                                         | A gitignored secret word list term was found in this write                                                                                                                                        | Ask the user what the cited entry covers, then remove the matching text                     |
| R-STAGED-LINT-FAILURE              | a staged file fails the cheap syntax check at commit time                                                          | lint_on_edit only ever runs at Write/Edit time, so a git add of pre-existing content skips it entirely                                                                                            | Fix the failing file(s) above and re-stage before committing                                |
| R-SUDO-PIP-INSTALL                 | `sudo pip install`                                                                                                 | Conflicts with the OS package manager and can corrupt system Python                                                                                                                               | Use a virtual environment or `pip install --user` instead                                   |
| R-TDD-TEST-FIRST                   | creating a production source file without its test file                                                            | TDD requires the test file to exist before the source file                                                                                                                                        | Create the test file first (RED), then the source file (GREEN)                              |
| R-INSTRUCTION-IMPLEMENTATION-LOG   | implementation logs (e.g. 'created the file X', 'added the class Y')                                               | Instruction files hold permanent instructions, not a log of past edits                                                                                                                            | Remove the log sentence; put implementation history in git or a plan JOURNAL/               |
| R-INSTRUCTION-STATUS-INDICATOR     | status indicators (e.g. checkmark + 'Complete', 'Done', 'Success', 'Fixed')                                        | A completion emoji records a moment in time, not a permanent fact                                                                                                                                 | Remove the status marker; instruction files describe the project, not its history           |
| R-INSTRUCTION-TIMESTAMP            | timestamps (ISO dates such as 2024-03-15)                                                                          | A dated entry is a log line, and instruction files are not a log                                                                                                                                  | Remove the date; if it is genuinely load-bearing, put it in git history                     |
| R-INSTRUCTION-LLM-SUMMARY          | LLM summaries (section headings such as '## Summary', '## Key Points', '## Overview')                              | A summary heading is the shape an LLM's own turn-report takes, not project documentation                                                                                                          | Remove the heading and fold any durable content into the surrounding instructions           |
| R-INSTRUCTION-TEST-OUTPUT          | test output counts (e.g. '42 tests passed', '1 test failed')                                                       | A test run's result is a point-in-time fact, not a stable instruction                                                                                                                             | Remove the count; CI already reports this on every run                                      |
| R-INSTRUCTION-FILE-LISTING         | changelog-style file listings (e.g. 'created src/Service/Foo.php')                                                 | A file path preceded by a past-tense action verb is changelog narrative                                                                                                                           | Remove the log line; a bare path reference used as documentation stays allowed              |
| R-INSTRUCTION-CHANGE-SUMMARY       | change summaries (e.g. 'Added 15 lines', 'Removed 8 lines')                                                        | A line-count delta describes one diff, not a stable instruction                                                                                                                                   | Remove the summary; the diff itself is preserved in git                                     |
| R-INSTRUCTION-COMPLETION-INDICATOR | completion indicators (e.g. 'ALL DONE!', 'Task complete!', 'Finished task')                                        | A completion phrase announces a session's end, not a fact about the project                                                                                                                       | Remove the phrase; instruction files should never celebrate finishing a task                |
| R-VERIFICATION-RESULT-NOT-CONSUMED | a verifier followed by a mutator with nothing consuming the result                                                 | The verifier can fail and the mutator would still run                                                                                                                                             | Gate with `&&`, an explicit exit-code check, or `set -euo pipefail`                         |
| R-WORKTREE-FILE-COPY               | `cp`/`mv`/`rsync` between a worktree and the main repo                                                             | Defeats worktree isolation, bypasses git tracking, and can nuke untracked work in the target directory                                                                                            | cd into the worktree, commit, then git merge back                                           |
| R-WRITE-CLOBBER                    | `Write` to an existing file you have not read this session                                                         | You cannot know what you are destroying, so you could not report the loss even afterwards                                                                                                         | `Read` the file then retry, or use `Edit` for a targeted change                             |
| R-LINT-FAILURE                     | a written/authored file that fails its language's lint check                                                       | The write has already landed on disk; this is a failure report, not a rollback                                                                                                                    | Fix the reported problems with Edit — do not re-Write the file from scratch                 |
| R-FAILSAFE-CRON-SUPPRESSED         | A delivered failsafe-cron tick, while a 'blocked only on human input' marker is live                               | Every tick against a session blocked only on human input is a guaranteed no-op model turn                                                                                                         | Nothing to do -- this is expected. Send a real message to clear the marker and resume ticks |
| R-STOP-QA-FAILURE                  | Stopping while the last QA tool run's own output indicated failure                                                 | QA failures detected in the last QA tool run                                                                                                                                                      | Fix the failures, re-run the QA tool, and continue without stopping                         |
| R-STOP-TAUTOLOGICAL-QUESTION       | Stopping behind a rhetorical continue/confirmation question                                                        | The answer is obvious -- yes, continue the already-planned work now                                                                                                                               | Resume the next unit of work immediately; STOPPING BECAUSE: does not exempt this            |
| R-STOP-AFTER-TOOL-ERROR            | Stopping right after an unresolved tool_use_error                                                                  | The correct action is to address the cause and retry, not stop                                                                                                                                    | Address the tool_use_error's cause (e.g. Read before Edit/Write) and retry                  |
| R-STOP-CONFIRMATION-QUESTION       | Stopping to ask an obvious confirmation question                                                                   | The daemon auto-continues through confirmation-style questions                                                                                                                                    | Proceed with the remaining work; stop with STOPPING BECAUSE: only if truly stuck            |
| R-STOP-NO-REASON                   | Stopping without a STOPPING BECAUSE: explanation                                                                   | The stop hook enforces intentional stops                                                                                                                                                          | Prefix your stop message with STOPPING BECAUSE: <reason>, or keep working                   |
| R-STOP-GOAL-LEDGER                 | Stopping while ledgered plan(s) are still In Progress                                                              | The daemon-side goal ledger owes a goal for EVERY In Progress plan, not only the newest /goal condition                                                                                           | Continue the listed plan(s), or stop with STOPPING BECAUSE: naming why each cannot proceed  |

## Advisories and other active handlers

One line each; these fire with their own guidance when relevant. Full text: `bin/hooks-daemon explain-handler <name>`.

<!-- handler: agent-isolation-advisor -->

- agent_isolation_advisor — isolate concurrent agents

<!-- handler: dispatch-declaration -->

- dispatch_declaration — declare where a subagent's reports go

<!-- handler: flaggable-work-advisor -->

- flaggable_work_advisor — delegate flaggable work BEFORE reading it

<!-- handler: plan-workflow-guidance -->

- plan_workflow — PLAN.md, supporting docs and JOURNAL/ obey DIFFERENT contracts

<!-- handler: background-process-tracker -->

- background_process_tracker — backgrounded processes are tracked

<!-- handler: budget-exhaustion-detector -->

- budget_exhaustion_detector — hidden agent budgets are surfaced

<!-- handler: command-hints -->

- command_hints — advisory reminders after specific commands

<!-- handler: git-hooks-executable-fixer -->

- git_hooks_executable_fixer — auto-fixes non-executable git hooks

<!-- handler: goal-injection -->

- goal_injection — plan-start goal signal for the ccy supervisor

<!-- handler: recovery-cron-advisor -->

- recovery_cron_advisor — failsafe recovery cron lifecycle advisory

<!-- handler: ccy-supervisor-integrity -->

- ccy_supervisor_integrity — keep the ccy supervisor properly set up

<!-- handler: docs-qa-sweep -->

- docs_qa_sweep — documentation drift report at session start

<!-- handler: git-upstream-checker -->

- git_upstream_checker — additive fetch + pull/cleanup advice on session start

<!-- handler: hook-registration-checker -->

- hook_registration_checker — hooks configuration policy

<!-- handler: model-fallback-detector -->

- model_fallback_detector — silent model substitution is surfaced

<!-- handler: plan-qa-sweep -->

- plan_qa_sweep — plan-tree drift report at session start

<!-- handler: plan-workflow-asset-checker -->

- plan_workflow_asset_checker — plan tooling provisioning alert

<!-- handler: project-handler-load-checker -->

- project_handler_load_checker — project protection degraded alert

<!-- handler: secret-file-hygiene-checker -->

- secret_file_hygiene_checker -- on-disk hygiene for protected paths

<!-- handler: tool-disable-advisor -->

- tool_disable_advisor — declared never-want tools are checked at session start

<!-- handler: standing-authorisations -->

- standing_authorisations — a project can record a standing request

<!-- handler: auto-approve-reads -->

- auto_approve_reads — gated on bypassPermissions mode

<!-- handler: subagent-report-size-blocker -->

- subagent_report_size_blocker — write large reports to a file

<!-- handler: worktree-create -->

- worktree_create — semantic worktree naming

<!-- handler: nitpick-dismissive-language -->

- nitpick.dismissive_language — do not deflect or prematurely halt

<!-- handler: nitpick-hedging-language -->

- nitpick.hedging_language — the guessing is the defect, not the wording

</hooksdaemon>
