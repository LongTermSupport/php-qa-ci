# PHP-QA-CI Library Documentation

## Overview

PHP-QA-CI is a comprehensive quality assurance pipeline for PHP projects written in Bash. It orchestrates multiple PHP quality assurance tools in a carefully designed sequence to fail fast and provide rapid feedback.

## Architecture

### Core Components

1. **Main Script**: `bin/qa` - Entry point that orchestrates all tools
2. **Tool Runners**: Individual bash scripts in `includes/generic/` that run specific tools
3. **Configuration System**: Cascading configuration from defaults to project overrides
4. **Platform Detection**: Automatic detection of Symfony/Laravel/generic platforms

### How It Works

When you run the qa script in your project:

1. The script detects your project root and platform type
2. Loads configuration from multiple sources (defaults → platform-specific → project overrides)
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

2. **Platform Detection** (`detectPlatform`) - Identifies if project is Symfony/Laravel/generic

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

8. **PHIVE Install** - If `phive.xml` exists, runs `scripts/phive-install.bash` to install PHAR dependencies

9. **Pre-Hook** (`hookPre.bash`) - Runs project-specific pre-pipeline script if exists

Only after all preflight steps complete does the actual tool execution begin.

### Main Tool Execution Phases

The pipeline runs tools in 4 distinct phases:

### Phase 1: Coding Standards Tools (can modify code)

1. **Rector** (`rector`) - Automated refactoring and code upgrades
2. **PHP CS Fixer** (`phpCsFixer`) - Code style fixing

### Phase 2: Linting Tools (validation only)

3. **PSR-4 Validation** (`psr4Validate`) - Validates namespace/directory structure
4. **Composer Checks** (`composerChecks`) - Runs composer diagnose and dumps autoloader
5. **Strict Types Enforcement** (`phpStrictTypes`) - Ensures `declare(strict_types=1)` in all PHP files
6. **PHP Lint** (`phpLint`) - Fast parallel syntax checking
7. **PHPUnit Annotations Check** (`phpunitAnnotations`) - Validates test annotations
8. **Composer Require Checker** (`composerRequireChecker`) - Checks for missing dependencies
9. **Markdown Links Checker** (`markdownLinks`) - Validates links in markdown files

### Phase 3: Static Analysis Tools

10. **PHPStan** (`phpstan`) - Static analysis tool
11. **PHPArkitect** (`phpArkitect`) - Architecture rules (class naming, namespace layering, dependency direction). On by default; applies a generic-safe baseline and is composable/overridable per project. Opt out with `export useArkitect=0`. See the [PHPArkitect section in README.md](README.md#phparkitect-architecture-rules).
12. **SensitiveParameter Usage** (`sensitiveParameterUsage`) - Always-on security baseline: fails if `#[\SensitiveParameter]` is used nowhere in `src/`. Opt out per-project with `export useSensitiveParameterCheck=0`.

### Phase 4: Testing Tools

11. **PHPUnit** (`phpunit`) - Unit testing framework
12. **Infection** (`infection`) - Mutation testing (optional, requires `useInfection=1`)

### Post-Success Phase (After all tests pass)

After the "ALL TESTS PASSING" message:

13. **PHPLoc** (`phploc`) - Generates code statistics (lines of code, complexity, etc.)

    - This is informational only and cannot fail the pipeline
    - Provides metrics about code size and structure

14. **Post-Hook** (`hookPost.bash`) - Runs project-specific post-pipeline script if exists

    - Only runs if all previous tools passed
    - Common uses: generate reports, notifications, cleanup

### Final Steps

- **Retry Warning** - If any tools were retried during the run, displays a warning
- **Completion Message** - Shows hostname and completion status

## Configuration System

### Configuration Cascade

Configuration is resolved in this order (later overrides earlier):

1. **Built-in defaults** in `configDefaults.inc.bash`
2. **Platform-specific defaults** in `configDefaults/{platform}/`
3. **Tool-specific defaults** in `configDefaults/generic/` (e.g., `php_cs.php`, `phpstan.neon`)
4. **Project config** in `{project}/qaConfig/qaConfig.inc.bash`
5. **Project tool configs** in `{project}/qaConfig/` (e.g., `php_cs.php`, `phpstan.neon`)
6. **Project tool overrides** in `{project}/qaConfig/tools/{toolName}.inc.bash`

### Key Configuration Variables

```bash
# PHP binary path
phpBinPath=${PHP_QA_CI_PHP_EXECUTABLE:-$(which php)}

# Skip long-running tests
phpqaQuickTests=${phpqaQuickTests:-0}

# PHPUnit specific
phpUnitQuickTests=${phpUnitQuickTests:-0}
phpUnitCoverage=${phpUnitCoverage:-0}
phpUnitIterativeMode=${phpUnitIterativeMode:-0}

# Infection
useInfection=${useInfection:-1}  # Disabled if no xdebug/coverage

# CI mode
CI=${CI:-'false'}

# Skip uncommitted changes check
skipUncommittedChangesCheck=${skipUncommittedChangesCheck:-0}
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
- **Laravel**: Presence of `artisan` file
- **Generic**: Default for all other PHP projects

Platform-specific tool configurations are loaded from `includes/{platform}/`.

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
- **Updated PHP CS Fixer config** to use `@PHP84Migration` ruleset
- **Added nullable type rules** for PHP 8.4's deprecation of implicit nullable parameters
- **PHP CS Fixer v3.84.0+** supports PHP 8.4 natively (no `PHP_CS_FIXER_IGNORE_ENV` needed)

### PHP 8.4 Specific Configuration

```php
// In configDefaults/generic/php_cs.php
'@PHP84Migration' => true,
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

## Environment Requirements

- Linux/Unix environment (uses bash)
- PHP 7.4 or higher (PHP 8.4 supported on php8.4 branch)
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

### PHP Strict Types

- **Purpose**: Ensures all PHP files have `declare(strict_types=1)`
- **Tool**: [@includes/generic/phpStrictTypes.inc.bash](includes/generic/phpStrictTypes.inc.bash)
- **How it works**: Finds PHP files missing strict types declaration, optionally adds it automatically
- **Interactive**: In non-CI mode, asks before adding to each file

### PHP Lint

- **Purpose**: Fast parallel syntax checking
- **Tool**: [@includes/generic/phpLint.inc.bash](includes/generic/phpLint.inc.bash)
- **How it works**: Uses PHP's built-in `-l` flag to check syntax, runs in parallel for speed
- **Key features**:
  - Much faster than full parsing
  - Catches parse errors before running other tools

### PHPUnit Annotations Check

- **Purpose**: Validates PHPUnit test annotations
- **Tool**: [@includes/generic/phpunitAnnotations.inc.bash](includes/generic/phpunitAnnotations.inc.bash)
- **Binary**: `bin/phpunit-check-annotation`
- **How it works**: Parses test files to ensure proper @test, @group annotations

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
- **PHAR**: `vendor-phar/phparkitect.phar` (PHIVE, key `D9C905CED1932CA2`)
- **Entry config (default)**: [@configDefaults/generic/phparkitect.php](configDefaults/generic/phparkitect.php) — applies the default tier to the detected source dir when a project has no `qaConfig/phparkitect.php`
- **Rule tiers**: `phparkitect-rules-default.php` (on by default), `phparkitect-rules-optional.php` + `phparkitect-rules-optional-symfony.php` (opt-in) under [@configDefaults/generic](configDefaults/generic)
- **Project template**: [@templates/qaConfig-phparkitect.php](templates/qaConfig-phparkitect.php)
- **How it works**: parses each class into an AST and matches expressions (naming, dependencies); rules and the paths to scan are defined inside the config (so `-p` does not apply). The pipeline passes `--autoload` and exports the tier paths + detected `srcDir` as env vars
- **Where a rule belongs (PHPArkitect vs PHPStan)**: arkitect by default for structural rules; upgrade to a PHPStan rule only for finer-grained / method-level / semantic detection arkitect cannot express. **Never enforce one convention in both engines** — migrate, don't duplicate (SSoT). Full decision guide: [README.md "Where does a rule belong"](README.md#where-does-a-rule-belong--phparkitect-or-phpstan)
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
5. **Fail-fast design** - Pipeline stops on first tool failure (except in retry mode)

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
