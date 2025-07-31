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

When you run `./bin/qa` in your project:

1. The script detects your project root and platform type
2. Loads configuration from multiple sources (defaults → platform-specific → project overrides)
3. Runs tools from your project's `vendor/bin` directory (NOT from php-qa-ci's own vendor)
4. Executes tools in 4 phases in a specific order designed to modify code first, then validate

## Pipeline Execution Order

### Preflight Phase (Configuration & Setup)

Before running any QA tools, the pipeline executes these preflight steps:

1. **Platform Detection** (`detectPlatform`) - Identifies if project is Symfony/Laravel/generic
2. **Xdebug Check** - Determines if coverage/infection testing is available
3. **Set Paths** (`setPaths`) - Auto-detects and configures paths:
   - `testsDir` - Finds test directory
   - `srcDir` - Finds source directory  
   - `binDir` - Finds bin directory (vendor/bin)
   - `pathsToCheck` - Array of paths to scan (defaults to tests + src)
   - `pathsToIgnore` - Array of paths to ignore
4. **Set Config** (`setConfig`) - Loads all configuration files in cascade order
5. **Project Config Override** - Sources `qaConfig/qaConfig.inc.bash` if it exists
6. **Prepare Directories** (`prepareDirectories`) - Creates necessary directories:
   - `var/qa/` - Main QA output directory
   - `var/qa/cache/` - Tool cache directory
   - Adds .gitignore files to exclude generated content
7. **Pre-Hook** (`hookPre.bash`) - Runs project-specific pre-pipeline script if exists

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

## Environment Requirements

- Linux/Unix environment (uses bash)
- PHP 7.4 or higher (PHP 8.4 supported on php8.4 branch)
- Composer-installed project with php-qa-ci as a dependency

### Custom PHP Executable

You can specify which PHP binary to use via the `PHP_QA_CI_PHP_EXECUTABLE` environment variable:

```bash
# Use specific PHP version
PHP_QA_CI_PHP_EXECUTABLE=/usr/bin/php8.4 ./bin/qa

# Or export for the session
export PHP_QA_CI_PHP_EXECUTABLE=/usr/bin/php8.4
./bin/qa
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
- **How it works**: 
  - Runs `composer diagnose` to check for issues
  - Runs `composer dump-autoload` to ensure autoloading works

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
- **Purpose**: Finds missing composer dependencies
- **Tool**: [@includes/generic/composerRequireChecker.inc.bash](includes/generic/composerRequireChecker.inc.bash)
- **Default**: [@configDefaults/generic/composerRequireChecker.json](configDefaults/generic/composerRequireChecker.json)
- **How it works**: Analyzes use statements and function calls, compares against composer.json
- **Key features**:
  - Finds dependencies used but not declared
  - Helps maintain accurate composer.json

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