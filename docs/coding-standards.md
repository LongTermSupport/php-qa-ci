# PHPQA Coding Standards

PHP-QA-CI uses two tools for coding standards enforcement, both running in Phase 1 (code modification) of the pipeline:

1. **Rector** -- Automated refactoring and PHP version migration
2. **PHP CS Fixer** -- Code style fixing

Both tools run before any linting or analysis, so that later phases validate the final state of the code.

## PHP CS Fixer

PHP CS Fixer runs as a **PHAR** from `vendor-phar/php-cs-fixer.phar` (not as a Composer dependency).

### Configuration

PHP CS Fixer is configured through the `php_cs.php` file. The default configuration is located at `configDefaults/generic/php_cs.php` and can be overridden by creating your own `qaConfig/php_cs.php` file.

The default configuration includes:

- `@PhpCsFixer` and `@Symfony` rule sets
- `@PHP8x4Migration` -- PHP 8.4 migration rules
- `nullable_type_declaration_for_default_null_value` -- Required for PHP 8.4 compatibility (implicit nullable parameters are deprecated)
- `nullable_type_declaration` with `question_mark` syntax
- `declare_strict_types` -- Enforces strict types in all files
- `final_class` -- Makes all classes final by default
- `strict_comparison` and `strict_param` -- Enforces strict comparisons
- `static_lambda` -- Converts closures to static where possible
- `native_function_invocation` -- Optimises native function calls

### Customizing the Configuration

To use a custom PHP CS Fixer configuration:

```bash
cd /project/root

mkdir -p qaConfig

# Copy and customize the default config
cp vendor/lts/php-qa-ci/configDefaults/generic/php_cs.php qaConfig/php_cs.php

# Edit the file to your needs
```

You can also override the file finder by copying `php_cs_finder.php`:

```bash
cp vendor/lts/php-qa-ci/configDefaults/generic/php_cs_finder.php qaConfig/php_cs_finder.php
```

### Running PHP CS Fixer Manually

To run PHP CS Fixer manually outside of the QA pipeline:

```bash
# Via the QA pipeline (recommended -- uses correct config)
vendor/bin/qa -t fixer

# Or directly via the PHAR
php vendor/lts/php-qa-ci/vendor-phar/php-cs-fixer.phar fix --config=qaConfig/php_cs.php --dry-run --diff
```

See the [PHP CS Fixer docs](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/master/doc/config.rst) for more information about configuration options.

## Rector

Rector is installed in an isolated sub-composer project at `tools/rector/` to prevent dependency conflicts with PHPStan.

The pipeline runs three Rector configurations in order:

1. **rector-safe.php** -- Converts standard PHP functions to `thecodingmachine/safe` equivalents
2. **rector-phpunit.php** -- Applies PHPUnit modernisation rules to the test directory
3. **rector-php84.php** -- Applies PHP 8.4 migration rules (skipped if a project-specific `rector.php` exists)

### Project-Specific Rector Configuration

If you have a `rector.php` in your project root or `qaConfig/rector.php`, the pipeline will run it instead of the default PHP 8.4 rector. The safe functions and PHPUnit rectors still run.

### Customizing Rector

```bash
# Create project-specific rector config
cp vendor/lts/php-qa-ci/configDefaults/generic/rector-php84.php qaConfig/rector.php
# Edit qaConfig/rector.php to your needs
```

### Running Rector Manually

```bash
# Via the QA pipeline
vendor/bin/qa -t rector
```
