# PHPQA Coding Standards

We use [PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) to handle coding standards and automatically fix code style issues.

## Configuration

PHP CS Fixer is configured through the `php_cs.php` file. The default configuration is located at `configDefaults/generic/php_cs.php` and can be overridden by creating your own `qaConfig/php_cs.php` file.

## Customizing the Configuration

To use a custom PHP CS Fixer configuration:

```bash
cd /project/root

# Create qaConfig directory
mkdir -p qaConfig

# Copy and customize the default config
cp vendor/lts/php-qa-ci/configDefaults/generic/php_cs.php qaConfig/php_cs.php

# Edit the file to your needs
```

## Ignoring Parts of a File

You can mark specific chunks of code to be ignored by PHP CS Fixer:

```php
<?php
$xmlPackage = new XMLPackage;
// @codingStandardsIgnoreStart
$xmlPackage['error_code'] = get_default_error_code_value();
$xmlPackage->send();
// @codingStandardsIgnoreEnd
```

Or for a single line:
```php
$someUglyCode = 'test'; // @codingStandardsIgnoreLine
```

## Running PHP CS Fixer Manually

To run PHP CS Fixer manually outside of the QA pipeline:

```bash
# Dry run to see what would be changed
./vendor/bin/php-cs-fixer fix --dry-run --diff

# Fix the code
./vendor/bin/php-cs-fixer fix
```

See the [PHP CS Fixer docs](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/blob/master/doc/config.rst) for more information about configuration options.