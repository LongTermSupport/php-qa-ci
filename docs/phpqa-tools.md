# PHPQA Tools

QA runs a suite of standard tools contained in the `includes/generic` folder. These are run from the `bin/qa` script via the `runTool` function.

The tools are run in a specific order across four phases, designed to modify code first (Phase 1), then validate (Phase 2), analyse (Phase 3), and test (Phase 4). The pipeline fails as quickly as possible.

In local development, a failed tool can be retried indefinitely. In CI, a failed tool fails the whole process.

## The Tool Runner

Each tool is run by calling the [`runTool`](./../includes/functions.inc.bash#L30) function.

The `runTool` function takes into account the platform that PHPQA detected via the [`detectPlatform`](./../includes/functions.inc.bash#L7) function.

You can override any tool for your project by copying it into `qaConfig/tools` and editing as you see fit.

- It will first check for project level overrides in `/project/root/qaConfig/tools/{toolName}.inc.bash`
- Then it will look inside `includes/{detectedPlatform}/{toolName}.inc.bash`
- If none is found, it will fall back to `includes/generic/{toolName}.inc.bash`

The platform-specific script will be run instead of the generic script. You can choose to `source` the generic tool in your script:

```bash
source $DIR/../includes/generic/setConfig.inc.bash
... platform-specific script contents ...
```

## Preflight: Uncommitted Changes Check

[Uncommitted Changes Check](./../includes/functions.inc.bash#L92)

Before the main tools run, the pipeline checks for uncommitted changes. Tools in Phase 1 will actively modify code, so you should be able to easily roll back changes if needed.

This check can be bypassed in two ways:

### CI Mode
```bash
export CI=true
vendor/bin/qa
```

## Phase 1: Code Modification

These tools can modify your source files.

### Rector

[Rector Tool](../includes/generic/rector.inc.bash)

Rector performs automated refactoring and code upgrades. It is installed in an isolated sub-composer project at `tools/rector/` (with its own `composer.json`) to prevent `phpstan/phpstan` leaking into the project's dependencies.

The pipeline runs Rector in three stages:

1. **Safe Functions** -- Converts standard PHP functions to their [thecodingmachine/safe](https://github.com/thecodingmachine/safe) equivalents (which throw exceptions instead of returning false). Requires `thecodingmachine/safe` as a production dependency.
2. **PHPUnit** -- Applies PHPUnit-specific rector rules to the test directory.
3. **PHP 8.4** -- Applies PHP 8.4 migration rules (skipped if a project-specific `rector.php` or `qaConfig/rector.php` is found, as it is assumed those handle version upgrades).

Default configurations:
- [rector-safe.php](../configDefaults/generic/rector-safe.php)
- [rector-phpunit.php](../configDefaults/generic/rector-phpunit.php)
- [rector-php84.php](../configDefaults/generic/rector-php84.php)

### PHP CS Fixer

[PHP CS Fixer Tool](../includes/generic/phpCsFixer.inc.bash)

PHP CS Fixer automatically fixes code style issues according to modern PHP standards. It runs as a **PHAR** from `vendor-phar/php-cs-fixer.phar` (not as a Composer dependency).

The default configuration includes `@PHP84Migration` rules for PHP 8.4 compatibility, including nullable type declarations.

Please see the [PHPQA Coding Standards docs](./coding-standards.md) for configuration details.

See the [PHP CS Fixer Docs](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) for more information.

## Phase 2: Linting and Validation

These tools validate code without modifying it.

### PSR-4 Validation

[PSR-4 Validation Tool](../includes/generic/psr4Validate.inc.bash)

Checks for code whose namespaces do not comply with the PSR-4 standard.

#### Ignore List

You can specify files or directories to be ignored by the validator. This is a newline-separated list of valid regex including a valid regex delimiter. For example:

```
#tests/bootstrap\.php#
#tests/phpstan-bootstrap\.php#
#tests/assets/.*?asset#
```

### Composer Checks

[Composer Checks Tool](../includes/generic/composerChecks.inc.bash)

- Checks that `ergebnis/composer-normalize` plugin is allowed
- Runs `composer diagnose` to check for issues
- Runs `composer normalize` to normalize `composer.json`
- Dumps the autoloader to ensure recent code changes will not cause autoloading issues

### Strict Types Enforcement

[Strict Types Tool](../includes/generic/phpStrictTypes.inc.bash)

Checks for PHP files that do not have `declare(strict_types=1)` and allows you to fix them.

### PHP Parallel Lint

[PHP Lint Tool](../includes/generic/phpLint.inc.bash)

Very fast PHP linting process. Checks for syntax errors in your PHP files.

See the [PHP Parallel Lint project page](https://github.com/php-parallel-lint/PHP-Parallel-Lint) for more information.

### Composer Require Checker

[Composer Require Checker Tool](../includes/generic/composerRequireChecker.inc.bash)

Ensures all code dependencies are explicitly declared in `composer.json`. Catches use of transitive dependencies that are not directly required.

This tool runs as a **PHAR** from `vendor-phar/composer-require-checker.phar`.

### Markdown Links Checker

[Markdown Links Checker Tool](../includes/generic/markdownLinks.inc.bash)

Checks your `README.md` file and all `*.md` files in the `docs` directory. For each link found, it ensures the link target is valid -- both internal links to project files and external links to remote web pages.

## Phase 3: Static Analysis

### PHPStan

[PHPStan Tool](../includes/generic/phpstan.inc.bash)

Static analysis of your PHP code. Runs as a **PHAR** from `vendor-phar/phpstan.phar`.

The default configuration runs at `level: max`.

PHP-QA-CI bundles custom PHPStan rules (auto-loaded via extension installer) and ships with `phpstan-strict-rules` and `phpstan-phpunit` as Composer dependencies.

Please see the [PHPQA PHPStan docs](./tools/phpstan.md) for full details.

See the [PHPStan project page](https://github.com/phpstan/phpstan) for more information about PHPStan in general.

## Phase 4: Testing

### PHPUnit

[PHPUnit Tool](../includes/generic/phpunit.inc.bash)

Runs your [PHPUnit](https://github.com/sebastianbergmann/phpunit) tests. PHPUnit is installed as a Composer dependency.

Please see the [PHPQA PHPUnit docs](./tools/phpunit.md) for full details.

### Infection

[Infection Tool](./../includes/generic/infection.inc.bash)

Mutation testing -- runs your PHPUnit tests but deliberately mutates your code in ways that should make your tests fail. If they don't, you "failed to kill the mutant".

Runs as a **PHAR** from `vendor-phar/infection.phar`. Requires Xdebug and code coverage enabled (`useInfection=1`).

Please see the [PHPQA Infection docs](./tools/infection.md) for full details.

## Post-Success

### PHPLoc

[PHPLoc Tool](../includes/generic/phploc.inc.bash)

Generates code statistics (lines of code, complexity). Informational only -- cannot fail the pipeline.
