# PHP-QA-CI Library Documentation

## Knowledge & Memory Policy (binding)

Persistent Claude memory is DISABLED for this project — never write to the
harness memory store (`~/.claude/projects/*/memory/`). ALL knowledge, memory
and context MUST be tracked in-repo, clean of secrets: durable operational
knowledge in `CLAUDE/*.md` (e.g. [CLAUDE/prepush-verification.md](CLAUDE/prepush-verification.md)
— the mandatory pre-push battery; pushing `php8.4` deploys to production),
programme/work records in `CLAUDE/Plan/`.

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

1. **Variable Initialization** (in `bin/qa`) - Core variables set before anything else:

   - `$qaDir` - The php-qa-ci library directory (where bin/qa lives)
   - `$projectRoot` - The project being tested
   - `$binDir` - The project's bin directory (usually vendor/bin)

2. **Platform Detection** (`detectPlatform`) - Identifies if project is Symfony (via `symfony.lock`) or generic

3. **Xdebug Check** - Determines if coverage/infection testing is available

4. **Set Paths** (`setPaths`) - Auto-detects and configures paths:

   - `testsDir` - Finds test directory
   - `srcDir` - Finds source directory
   - `binDir` - Finds bin directory (vendor/bin)
   - `pathsToCheck` - Array of paths to scan (defaults to tests + src)
   - `pathsToIgnore` - Array of paths to ignore

5. **Set Config** (`setConfig`) - Loads all configuration files in cascade order and defines:

   - `$projectConfigPath` - Project's qaConfig directory
   - `$varDir` - Project's var/qa directory
   - `$cacheDir` - Project's var/qa/cache directory
   - `$pharDir` - QA library's vendor-phar directory (for PHIVE-installed tools)
   - Various tool configuration paths

6. **Project Config Override** - Sources `qaConfig/qaConfig.inc.bash` if it exists

7. **Prepare Directories** (`prepareDirectories`) - Creates necessary directories:

   - `var/qa/` - Main QA output directory
   - `var/qa/cache/` - Tool cache directory
   - Adds .gitignore files to exclude generated content

8. **Tool Install** - Runs `scripts/tool-install.bash` unconditionally (`bin/qa`). `phive.xml` is a hard requirement: if it is missing the script prints an error and exits 1. In the default `install` mode it verifies the PHARs committed under `vendor-phar/` are present (PHIVE only re-fetches in the maintainer `update`/`--force` modes) and installs the isolated Rector composer sub-project under `tools/rector/` on first use.

9. **Pre-Hook** (`hookPre.bash`) - Runs project-specific pre-pipeline script if exists

10. **Locking** - Sources `includes/generic/lock.inc.bash` and acquires a run-level lock (`initLockSystem` / `acquireLock`) so concurrent `qa` invocations cannot collide. If another `qa` process already holds the lock, `acquireLock` aborts the run (`exit 1`) before any tool executes. Locking is run-level only — there are no per-tool timing hooks.

Only after all preflight steps complete does the actual tool execution begin.

### Main Tool Execution Phases

The pipeline runs tools in 4 distinct phases:

### Phase 1: Coding Standards Tools (can modify code)

1. **Rector** (`rector`) - Automated refactoring and code upgrades
2. **PHP CS Fixer** (`phpCsFixer`) - Code style fixing

### Phase 2: Linting Tools (validation only)

3. **PSR-4 Validation** (`psr4Validate`) - Validates namespace/directory structure
4. **Composer Checks** (`composerChecks`) - Runs composer diagnose and dumps autoloader
5. **Package Type Declaration** (`packageType`) - Always-on: requires `composer.json` to declare a `type` (see [docs/tools/packageType.md](docs/tools/packageType.md))
6. **Strict Types Enforcement** (`phpStrictTypes`) - Ensures `declare(strict_types=1)` in all PHP files
7. **PHP Lint** (`phpLint`) - Fast parallel syntax checking
8. **Composer Require Checker** (`composerRequireChecker`) - Checks for missing dependencies
9. **Markdown Links Checker** (`markdownLinks`) - Validates links in markdown files

### Phase 3: Static Analysis Tools

10. **Branch Name Policy** (`branchNamePolicy`) - Runs first in this phase. Always-on: enforces the PR branch-naming convention (see [CLAUDE/branch-policy.md](CLAUDE/branch-policy.md))
11. **PHPStan** (`phpstan`) - Static analysis tool
12. **PHPArkitect** (`phpArkitect`) - Architecture rules (class naming, namespace layering, dependency direction). On by default; applies a generic-safe baseline and is composable/overridable per project. Opt out with `export useArkitect=0`. See the [PHPArkitect section in README.md](README.md#phparkitect-architecture-rules).
13. **SensitiveParameter Usage** (`sensitiveParameterUsage`) - Always-on security baseline: fails if `#[\SensitiveParameter]` is used nowhere in `src/`. Opt out per-project with `export useSensitiveParameterCheck=0`.

### Phase 4: Testing Tools

14. **PHPUnit** (`phpunit`) - Unit testing framework
15. **Infection** (`infection`) - Mutation testing (optional, requires `useInfection=1`)

### Post-Success Phase (After all tests pass)

After the "ALL TESTS PASSING" message:

16. **PHPLoc** (`phploc`) - Generates code statistics (lines of code, complexity, etc.)

    - This is informational only and cannot fail the pipeline
    - Provides metrics about code size and structure

17. **Post-Hook** (`hookPost.bash`) - Runs project-specific post-pipeline script if exists

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

## absolute_path — always use absolute paths

The `Read`, `Write`, and `Edit` tools require absolute paths. Relative paths are blocked.

- **Correct**: `/workspace/src/main.py`, `/workspace/tests/test_utils.py`
- **Blocked**: `src/main.py`, `./config.yaml`, `../other/file.txt`

The working directory is `/workspace`. Prepend `/workspace/` to any relative path before calling these tools.

## curl_pipe_shell — never pipe curl/wget to bash/sh

Piping network content directly to a shell is blocked. It executes untrusted remote code without any inspection.

**Blocked**: `curl URL | bash`, `curl URL | sh`, `wget URL | bash`, `curl URL | sudo bash`

**Safe alternative**: download first, inspect, then execute:

```
curl -o /tmp/script.sh URL
cat /tmp/script.sh          # inspect
bash /tmp/script.sh         # execute if safe
```

## daemon_location_guard — do not cd into .claude/hooks-daemon/

Bash commands that change directory into `.claude/hooks-daemon/` (or `cd` into a daemon-internal subdirectory and then run something) are blocked. The daemon is an upstream dependency that must remain untouched in client repos.

**Run daemon CLI from the project root instead** — it always works regardless of cwd:

```
$PYTHON -m claude_code_hooks_daemon.daemon.cli status
$PYTHON -m claude_code_hooks_daemon.daemon.cli restart
$PYTHON -m claude_code_hooks_daemon.daemon.cli logs
```

If you need to inspect daemon source for debugging, use `Read` from the project root with the absolute path — never `cd` in. Do NOT edit anything inside `.claude/hooks-daemon/`; changes will be overwritten on the next upgrade.

## dangerous_permissions — chmod 777 is blocked

`chmod 777` and other world-writable permission commands are blocked. Overly permissive file permissions are a security vulnerability.

**Blocked**: `chmod 777`, `chmod 666`, `chmod a+w`, `chmod o+w`

**Use least-privilege permissions instead**:

- Executable scripts: `chmod 755` (owner rwx, group/other rx)
- Regular files: `chmod 644` (owner rw, group/other r)
- Private files: `chmod 600` (owner rw only)

## destructive_git — blocked git commands

The following git commands are permanently blocked and will always be denied:

| Command                  | Reason                                                                   |
| ------------------------ | ------------------------------------------------------------------------ |
| `git reset --hard`       | Permanently destroys all uncommitted changes                             |
| `git clean -f`           | Permanently deletes untracked files                                      |
| `git checkout -- <file>` | Discards all local changes to that file                                  |
| `git restore <file>`     | Discards local changes (`--staged` is allowed)                           |
| `git stash drop`         | Permanently destroys stashed changes                                     |
| `git stash clear`        | Permanently destroys all stashes                                         |
| `git push --force`       | Can overwrite remote history and destroy teammates' work                 |
| `git branch -D`          | Force-deletes branch without checking if merged (lowercase `-d` is safe) |
| `git commit --amend`     | Rewrites the previous commit — create a new commit instead               |

If the user needs to run one of these, ask them to do it manually. Do not attempt to work around the block.

**Safe alternatives**: `git stash` (recoverable), `git diff` / `git status` (inspect first), `git commit` (save changes permanently first).

## error_hiding_blocker — error-suppression patterns are blocked

Writing code that silently swallows errors is blocked. All errors must be handled explicitly.

**Blocked patterns (examples)**:

- Python: bare `except` clauses with an empty body, catching and discarding all exceptions
- Shell: redirecting stderr to `/dev/null` to silence failures, `|| true` to suppress non-zero exit codes
- JavaScript/TypeScript: empty `catch` blocks that swallow exceptions
- Go: `_ = err` (discarding error return values without handling)

**Required action**: Handle errors explicitly — log them, return them to the caller, or propagate them. Silent error suppression masks bugs and makes debugging impossible.

**Excluded paths**: vendor/, node_modules/, and test-fixture dirs (tests/fixtures/, tests/assets/, __fixtures__/) are skipped by default. Exempt more paths with glob patterns via `handlers.pre_tool_use.error_hiding_blocker.options.exclude_paths` or the project-wide `daemon.exclude_paths` — use these for fixtures of deliberately-broken code instead of disabling the handler.

## gh_issue_comments — always include --comments on gh issue view

`gh issue view` without `--comments` is blocked. Issue comments often contain critical context, clarifications, and updates not in the issue body.

**Blocked**: `gh issue view 123`, `gh issue view 123 --repo owner/repo`

**Allowed**: `gh issue view 123 --comments`, `gh issue view 123 --json title,body,comments`

If using `--json`, include `comments` in the field list instead of adding `--comments`.

## gh_pr_comments — always include --comments on gh pr view

`gh pr view` without `--comments` is blocked. PR comments often contain review feedback, reviewer requests, and decisions not in the PR body.

**Blocked**: `gh pr view 123`, `gh pr view 123 --repo owner/repo`

**Allowed**: `gh pr view 123 --comments`, `gh pr view 123 --json title,body,comments`

If using `--json`, include `comments` in the field list instead of adding `--comments`.

## git_stash — git stash is blocked by default

`git stash`, `git stash push`, and `git stash save` are blocked. `git stash pop`, `git stash apply`, `git stash list`, and `git stash show` are always allowed.

**Why**: stashes get forgotten, lost, and block `git pull`. Use `git commit -m 'WIP: ...'` instead — WIP commits are acceptable.

**Escape hatch** (when commit truly won't work):

```
MUST_STASH_BECAUSE="explain why"; git stash
```

Configure via `handlers.pre_tool_use.git_stash.options.mode: warn` for advisory-only mode.

## lock_file_edit_blocker — never directly edit lock files

Direct `Write` or `Edit` to package manager lock files is blocked. Lock files are generated artifacts; manual edits create checksum mismatches and broken dependency graphs.

**Blocked files**: `composer.lock`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `Gemfile.lock`, `Cargo.lock`, `go.sum`, `Package.resolved`, `Pipfile.lock`, and others.

**Use package manager commands instead**:

- PHP: `composer install` / `composer require package`
- Node: `npm install` / `yarn add package`
- Ruby: `bundle install` / `bundle add gem`
- Rust: `cargo add crate`
- Go: `go get module`

## pip_break_system — --break-system-packages is blocked

`pip install --break-system-packages` (and the `pip3` / `python -m pip` / `python3 -m pip` variants) is blocked. The flag bypasses PEP 668 system-package protection and corrupts the system Python environment in containers and on modern Linux distros.

**Use a virtualenv or `--user` install instead**:

```
python3 -m venv /tmp/venv && /tmp/venv/bin/pip install <package>
# or
pip install --user <package>
```

If a tool's installer insists on `--break-system-packages` (some quick-start scripts do), download it first, inspect, and run it inside a venv — do not shortcut by adding the flag.

### Pipe Blocker

Commands piped to `tail` or `head` are **blocked** — piping truncates output and causes information loss.

**Do NOT do the theatre** of capturing output to a file and then echoing the WHOLE file to stdout — that defeats the point and just bloats tokens.

**Preferred — `echd-capture`**: capture the FULL output, see only a preview.

```bash
# WRONG — blocked (and truncates):
pytest tests/ 2>&1 | tail -20

# RIGHT — full capture, bounded preview + path to the rest:
set -o pipefail
pytest tests/ 2>&1 | echd-capture 20
# prints the last 20 lines + '(full output: /…/command-output-….txt)'.
# Use --head N for the first N lines. pipefail keeps pytest's exit code visible.
```

**Alternative** (no pipe): `pytest tests/ > /tmp/out.txt 2>&1` then read selectively.

**Allowed** (whitelisted): `grep`, `rg`, `awk`, `sed`, `jq`, `ls`, `cat`, `git log`, `git tag`, `git branch`, and other cheap filtering commands.

**Add to whitelist** (if safe to pipe): set `extra_whitelist` in `.claude/hooks-daemon.yaml` under `pipe_blocker`.

## root_recursion_guard — recursive scans rooted at / are blocked

A recursive scanner whose path argument resolves to a catastrophic root location is blocked, because it walks the entire filesystem and can pin every CPU core for hours.

**Blocked** (recursive scanner + dangerous root path):

- `grep -r`/`-R`/`-rl`, `ugrep -r`, `rgrep`, `find`, `fd`/`fdfind`, `rg`
- pointed at `/`, `/proc`, `/sys`, `/home`, `/root`, `~`, `$HOME`

**Allowed**: the same scanners scoped to the project — `rg -l "x" /workspace`, `grep -rl "x" "$CLAUDE_PROJECT_DIR"`, `grep -rl x src/`, `find . -name y`. Non-recursive `grep x /etc/hosts` is not affected.

**Note**: `... | head` does NOT bound a `-l`/`-rl` scan — a producer that matches nothing never writes, so it never receives SIGPIPE and runs to completion across the whole disk.

**Escape hatch** (rare legitimate whole-disk scan):

```
MUST_SCAN_ROOT_BECAUSE="explain why"; grep -rl x /
```

## security_antipattern — OWASP security antipatterns are blocked

Writing code that contains security antipatterns is blocked across all supported languages. Fix the code to use safe patterns instead.

**Blocked categories**:

- SQL injection: building queries via string concatenation (use parameterised queries)
- Command injection: passing unvalidated input to subprocess (use argument lists)
- Hardcoded credentials: API keys, passwords, tokens embedded in source code
- Weak cryptography: MD5 or SHA1 for password hashing (use bcrypt/argon2)
- Path traversal: unvalidated user input used in file paths

**Supported languages**: Python, JavaScript/TypeScript, Go, PHP, Ruby, Java, Kotlin, C#, Rust, Swift, Dart.

**Excluded paths**: vendor/, node_modules/, and test fixtures are skipped by default. Exempt more paths with glob patterns via `handlers.pre_tool_use.security_antipattern.options.exclude_paths` or the project-wide `daemon.exclude_paths`.

## sed_blocker — sed is forbidden for file modification

`sed` is blocked because Claude gets sed syntax wrong and a single error can silently destroy hundreds of files with no recovery possible.

**Blocked**:

- `sed -i` / `sed -e` (in-place file editing via Bash tool)
- `grep -rl X | xargs sed -i` (mass file modification)
- Shell scripts (`.sh`/`.bash`) written via Write tool that contain `sed`

**Allowed** (read-only, no file modification):

- `cat file | sed 's/x/y/' | grep z` (pipeline transforming stdout only)
- `sed` mentioned in commit messages, PR bodies, or `.md` documentation files

**Use instead**:

- `Edit` tool — safe, atomic, verifiable
- Parallel Haiku agents with `Edit` tool for bulk changes across many files:
  1. Identify all files to update
  2. Dispatch one Haiku agent per file
  3. Each agent uses the `Edit` tool (never `sed`)

## sudo_pip — sudo pip install is blocked

`sudo pip install` (and the `sudo pip3` / `sudo python -m pip` / `sudo python3 -m pip` variants) is blocked. Installing as root corrupts the system Python managed by the OS package manager and creates permission/ownership issues that are painful to recover from.

**Use a virtualenv or `--user` install instead**:

```
python3 -m venv /tmp/venv && /tmp/venv/bin/pip install <package>
# or
pip install --user <package>
```

Even in a container running as root, `sudo` adds nothing — drop it and use a venv.

## worktree_file_copy — do not copy files between worktrees and the main repo

`cp`, `mv`, and `rsync` operations that move files from a worktree directory (`untracked/worktrees/` or `.claude/worktrees/`) into the main repo (`src/`, `tests/`, `config/`) — or vice versa — are blocked.

Worktrees are isolated branches. Cross-copying corrupts that isolation and can silently overwrite in-progress work.

**Allowed**: operations within the same worktree branch. **To merge changes**: use `git merge` or `git cherry-pick` instead.

## background_process_tracker — backgrounded processes are tracked

A PostToolUse advisory that fires when a Bash call backgrounds a process (`run_in_background: true`, or a `&`/`nohup`/`setsid`/`disown` command). It records the command to `background-processes.jsonl` and injects rate-limited guidance.

**The daemon never kills.** It surfaces runaways; you decide.

When you background a long-lived process:

- Create a non-durable recurring **watchdog cron** (CronCreate, durable:false) whose prompt runs `$PYTHON -m claude_code_hooks_daemon.daemon.cli harvest-background` and acts on any runaway — this covers the idle/compaction window a tool-call hook cannot. Do NOT wait for the cron; keep working.
- Check on demand: run `harvest-background` (exit 1 == runaways surfaced).
- Reap a runaway by its **process group**: `kill -- -<pgid>` (not just the pid).
- Keep a wanted long task: note `KEEP_RUNNING_BECAUSE="reason"`.
- Delete the watchdog cron (CronDelete) when no backgrounded work remains.

Advisory is rate-limited per session (default-on). Disable with `handlers.post_tool_use.background_process_tracker.enabled: false`.

## git_hooks_executable_fixer — auto-fixes non-executable git hooks

When a git command prints `hint: The '...' hook was ignored because it's not set as executable`, this handler automatically `chmod +x`s every non-`.sample` file in the repository's hooks directory (resolved via `git rev-parse --git-path hooks`, so worktrees and `core.hooksPath` are handled). Execute bits are added with least privilege (only where read is already granted). It never blocks the command and reports which hooks it fixed via advisory context. `.sample` files and already-executable hooks are left untouched.

## recovery_cron_advisor — failsafe recovery cron lifecycle advisory

An advisory PostToolUse handler that fires across a plan's lifecycle and
injects guidance telling the agent to manage a non-durable hourly failsafe
recovery cron.

### What it does

Three lifecycle phases are detected from Write/Edit to `CLAUDE/Plan/<digits>-<name>/PLAN.md`
(never from files inside `Completed/`) and from `mkplan.bash` Bash invocations:

| Phase          | Trigger                                                                               | Guidance injected                                                                                                                                                                                                                                         |
| -------------- | ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Creation**   | New PLAN.md written, or `mkplan.bash` invoked                                         | Create a non-durable hourly cron now (CronCreate, durable:false); record the ID in the plan; do NOT wait for the cron.                                                                                                                                    |
| **Progress**   | Edit to PLAN.md touching task-status icons (⬜/🔄/✅) or `## Notes & Updates` section | Confirm the recovery cron is still running (CronList); recreate if missing; keep working.                                                                                                                                                                 |
| **Completion** | `**Status**: Complete[d]` written/edited                                              | Plan complete — **warns first**: deleting now leaves the still-live session with no recovery coverage. Keep the cron if any further work may happen (it is non-durable and dies on session exit); `CronDelete` only when certain the session is finished. |

Progress reminders are rate-limited per plan: the handler advises on the first
progress edit and then once every few progress edits for that plan, so it does
not spam context on every edit. Completion always advises (bypasses the interval).

### CRITICAL: recovery cron is NOT a heartbeat

The recovery cron is a **failsafe safety net**, not a pacing mechanism:

- The agent **must never** wait for the cron between units of work.
- Work proceeds at **full speed** until an external factor (Claude API error,
  rate limit, 5-hour usage limit, network failure) actually stalls it.
- The cron fires only while the REPL is idle; it cannot interrupt active work.
- Treating the cron as a heartbeat is an **own goal** — it would convert a
  safety net into an artificial hourly throttle.

### Canonical recovery-cron prompt

Use this verbatim as the CronCreate prompt:

```
**FAILSAFE RECOVERY CHECK (automated hourly safety net — NOT a heartbeat).**
If your most recent work on the active plan/task was interrupted by an
*external* factor (Claude API error/overload, rate limit, 5-hour usage limit,
network failure) and is now resumable, resume it immediately and carry it to
completion. If you are blocked **only** on human input, do nothing and keep
waiting. If work is already proceeding normally, this is a **no-op** — do not
interrupt, restart, or duplicate anything in flight. Never treat this as a
heartbeat or pacing signal: between checks, continue at full speed until an
external factor actually stops you — waiting for the cron is an own goal. Do
NOT delete this cron merely because a tick finds nothing to resume: it is
non-durable and ends automatically when the session exits, and a still-live
session stays exposed to the next rate limit without it. Remove it (CronDelete)
only once the session is genuinely finished with no further work.
```

### Configuration

This handler is **on by default** (opt-out). Disable with:

```yaml
handlers:
  post_tool_use:
    recovery_cron_advisor:
      enabled: false
```

## ccy_supervisor_integrity — keep the ccy supervisor properly set up

At session start this handler checks a ccy project (`.claude/ccy/`) whose supervisor is **armed** (`ccy.env` exports `CCY_CLAUDE_WRAPPER` referencing `claude-supervise.py`). It warns — never blocks — when the setup is brick-risky:

- **`claude-supervise.py` missing** → the launcher's `exec` fails. Redeploy via a daemon upgrade or restore from git.
- **not executable** → `chmod +x .claude/ccy/claude-supervise.py`.
- **git-ignored** → it won't be committed; teammates get a broken supervisor. Add a `!claude-supervise.py` / `!ccy.env` whitelist line to `.claude/ccy/.gitignore` and commit the files.
- **`ccy.deploy_supervisor: false` while armed+present** → the installer skips deploy on `false`, so upgrades never refresh `claude-supervise.py` and the project runs an increasingly stale supervisor. Set it to `true` (or disarm `CCY_CLAUDE_WRAPPER` if you truly want it off).

It also detects a **stale running supervisor** (Plan 00164): when a daemon upgrade has put a NEWER `claude-supervise.py` on disk than the live process (compared by source fingerprint, not just version), it advises restarting ccy so the wrapper re-execs the updated supervisor. Nothing is broken meanwhile — the old supervisor keeps working until the session is relaunched.

When you see this alert, fix the listed item(s) and commit the ccy files so the supervisor works for everyone.

## hook_registration_checker — hooks configuration policy

On every new session this handler audits hook configuration across `.claude/settings.json` and `.claude/settings.local.json`. When it reports issues, fix them — do not ignore the warning.

### Policy

1. **All hooks live in `settings.json`.** That file is tracked in version control, visible to teammates, and is the single source of truth for the daemon.
2. **`settings.local.json` must contain ZERO `hooks` entries.** It exists for per-developer `permissions` and IDE state only. A `hooks` block there is either (a) invisible to the rest of the team, or (b) duplicated with `settings.json` — in which case the hook fires twice per event.
3. **Hook commands must invoke the daemon wrapper.** Every registered command must end with `/.claude/hooks/{event}`. Anything else (inline Python, custom shell scripts, bespoke paths) is a legacy setup that bypasses the daemon entirely.

### Remediation

- **Hooks in `settings.local.json`**: move each `hooks` entry to `settings.json`, then delete the `hooks` key from `settings.local.json`. Confirm no duplicates remain.
- **Legacy-style commands**: replace them with a project-level handler. Run `$PYTHON -m claude_code_hooks_daemon.daemon.cli init-project-handlers` to scaffold `.claude/project-handlers/`, port the logic into a handler class, then restore the daemon wrapper in `settings.json`. The daemon will auto-discover the new handler on restart.
- **Missing hooks**: the daemon's installer writes the full set. If any are missing, re-run `install.py` or manually add the missing `{event_name}` entry pointing at `"$CLAUDE_PROJECT_DIR"/.claude/hooks/{bash-key}`.
- **Duplicate hooks**: a hook registered in both files fires twice. Keep the `settings.json` entry, delete from `settings.local.json`.

## plan_qa_sweep — plan-tree drift report at session start

At the start of each new session the plan directory is swept with the
plan QA check catalogue (index/folder bijection, number collisions,
statistics recount, archive structure, status-vs-location coherence,
staleness). Findings are injected once as advisory context — the
sweep never blocks.

**When a drift report appears**: fix the listed findings (each names
its exact remediation) as part of your plan housekeeping, then
re-check with:

```
$PYTHON -m claude_code_hooks_daemon.daemon.cli plan-qa --sweep
```

The CLI exits 1 while findings remain (CI-able). Single-file lint:
`plan-qa --lint <PLAN.md>`; staged-commit check: `plan-qa --check-staged`.
Policy lives under `plan_workflow.qa` in `.claude/hooks-daemon.yaml`
(archive dir names, staleness window, legacy/collision allowlists).

## project_handler_load_checker — project protection degraded alert

At session start this handler reports any **project handlers** (`.claude/project-handlers/`) that FAILED to load in the running daemon. A skipped handler is a silently-disabled protection — the alert exists so you never assume a guardrail is active when it is not.

### When you see `🚨 PROJECT PROTECTION DEGRADED 🚨`

1. **Do not assume normal guardrails are in force.** The listed handlers are OFF for this session.
2. **Diagnose** each failure: `$PYTHON -m claude_code_hooks_daemon.daemon.cli validate-project-handlers` names the file, the missing method, and the daemon version that introduced it.
3. **Fix** the handler(s) — usually adding a required method stub (e.g. `get_claude_md`) that a daemon upgrade made mandatory.
4. **Restart the daemon** (`$PYTHON -m claude_code_hooks_daemon.daemon.cli restart`). The alert reflects the *running* daemon, so it clears only after a restart reloads the fixed handlers — fixing the file alone is not enough.

The handler is silent when every project handler loads, so seeing this alert always means real action is required.

## auto_approve_reads — gated on bypassPermissions mode

Read-only tool permission requests (`Read`, `Glob`, `Grep`) are auto-approved **only** when Claude Code reports `permission_mode == "bypassPermissions"` (YOLO mode).

In every other mode (`default`, `plan`, `acceptEdits`, `dontAsk`) the handler defers and Claude Code's normal approval prompt is shown — the user has not opted out of per-tool approvals, so the daemon must not silently approve on their behalf.

If a permission prompt for `Read` appears in `default` mode, that is correct behaviour — approve it via Claude Code's UI.

### Stop Explanation Required

Before stopping, **prefix your final message** with `STOPPING BECAUSE:` followed by a clear reason:

```
STOPPING BECAUSE: all tasks complete, QA passes, daemon restart verified.
```

**Why**: The stop hook enforces intentional stops. Stopping without an explanation triggers an auto-block that asks you to explain or continue.

**Alternatives**:

- `STOPPING BECAUSE: <reason>` — stops cleanly with explanation
- Continue working — no need to stop unless all work is genuinely complete

**Do NOT**:

- Stop mid-task without explanation
- Ask confirmation questions and then stop (the hook auto-continues those)
- Smuggle a rhetorical continue question inside a `STOPPING BECAUSE:` message ('STOPPING BECAUSE: slice 1 done. Want me to build slice 2?') — this is HARD-BLOCKED; the prefix does not exempt tautological questions. Just continue with the next unit of work
- Use `AUTO-CONTINUE` unless you intend to keep working indefinitely

**Before asking a question, evaluate it critically**:

- Tautological/rhetorical questions with obvious answers ("Should I continue?", "Would you like me to proceed?") — do NOT ask, just do it
- Errors with a clear next step ("The test failed, should I fix it?") — do NOT ask, just fix it
- Genuine choice questions where all options are valid ("Which of A, B, or C should we use?") — these deserve a response. Use `STOPPING BECAUSE: need user input` and ask your question

**Recovering from a `tool_use_error` — do NOT stop silently**:

Some tool errors require an explicit recovery action, not a halt. The most common shape:

- You call `Edit` or `Write` on a file you have not yet read.
- Claude Code returns a `tool_use_error` (e.g. "File has not been read yet").
- The correct recovery is **Read the file, then retry Edit/Write** — **do not stop**. Stopping silently after a tool error triggers a Stop-hook re-entry loop and wastes a turn.

**Rule: Read before Edit/Write.** If you must edit a file you have not read, Read it first in the same turn. The daemon's Stop handler will detect a `tool_use_error` followed by a silent stop and re-fire to force recovery.

**On Stop hook re-entry (the hook fires again after a prior block)**: your next response is treated like any other — it must either prefix with `STOPPING BECAUSE:` or continue the work. Re-entry does not exempt you from the explanation rule.

## dismissive_language_detector — do not deflect or prematurely halt

Stop-time advisory that fires on language patterns signalling avoidance of work. The handler does NOT block the stop, but injects context for the next turn so the agent self-corrects. Identical advisories (same session, same phrase set) are emitted once, not repeated on every subsequent stop.

**Avoid**:

- Dismissing issues as `pre-existing`, `out of scope`, `not our problem`, or `not relevant` to deflect work that is in fact yours.
- Premature-halt phrasing like `natural checkpoint`, `ready to continue on your   cue`, `pausing here` mid-plan when there is more to do — finish the task rather than dressing up a halt.
- Speculative `should be fine` or `probably works` when verification is cheap (run the test, read the file).

**Do**: acknowledge the issue, fix it, or — if it genuinely is out of scope — say so once with the specific reason and continue with the in-scope work.

</hooksdaemon>
