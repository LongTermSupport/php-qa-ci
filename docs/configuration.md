# PHPQA Configuration

There are multiple ways to configure PHPQA and the component tools.

The general strategy is that there is an extensive and relatively sensible default configuration. In your project you should hopefully only need to make minor adjustments by overriding it.

## qaConfig Folder

A key concept with PHPQA is that there should be a directory in your project root called `qaConfig`. This folder is used as much as possible to contain all of your QA and CI configuration, and is where you store your project-specific configuration and hooks.

## Environment Variables

PHPQA makes extensive use of bash environment variables for configuration.

There are three ways you can generally set these environment variables:

1. Export them in your Bash session

```bash
export environmentVariable="value"

vendor/bin/qa
```

2. Set them inline when running PHPQA
```bash
environmentVariable="value" vendor/bin/qa
```

3. Export them as part of your CI script
```bash
#ci.bash
export CI=true
vendor/bin/qa
```

Here are some general PHPQA environment variables you might want to set:

##### Quick tests only:
 `phpqaQuickTests`

 Setting this to `1` **skips whole phases**, not just slow tests: PHPStan (Phase 3) and both
 PHPUnit and Infection (Phase 4) are skipped entirely. Use it for a fast lint/style/validation
 pass. Do not confuse it with `phpUnitQuickTests`, which is narrower — it still runs PHPUnit but
 lets individual tests take a faster path (see the PHPUnit docs).

##### CI Mode:
 `CI`

 Will not prompt for user input.

##### Memory limit:
 `phpqaMemoryLimit`

 Global memory limit for all QA tools. Default: `4G`.

```bash
# Override via environment variable
phpqaMemoryLimit=8G vendor/bin/qa

```

```php
// Or in qaConfig/qa.php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa->withMemoryLimit('8G');
```

## Configuration Files

The bulk of the configuration is handled with configuration files under
[configDefaults/](./../configDefaults). Only a `generic/` folder ships today — there are no
per-platform config folders. The `ConfigPathResolver` lookup still supports a platform rung
(`configDefaults/{platform}/`), but because no such folder exists it always falls through to
`generic/` unless your project supplies its own override (see below).

PHPQA's [configDefaults/generic](./../configDefaults/generic) folder contains the default config
files. At the moment this includes:

- [infection.json](./../configDefaults/generic/infection.json)
- [phpstan.neon](./../configDefaults/generic/phpstan.neon)
- [phpunit.xml](./../configDefaults/generic/phpunit.xml)
- [php_cs.php](./../configDefaults/generic/php_cs.php)
- [php_cs_finder.php](./../configDefaults/generic/php_cs_finder.php)
- [psr4-validate-ignore-list.txt](./../configDefaults/generic/psr4-validate-ignore-list.txt)
- [composerRequireChecker.json](./../configDefaults/generic/composerRequireChecker.json)
- [rector-safe.php](./../configDefaults/generic/rector-safe.php)
- [rector-phpunit.php](./../configDefaults/generic/rector-phpunit.php)
- [rector-php85.php](./../configDefaults/generic/rector-php85.php)
- [phparkitect.php](./../configDefaults/generic/phparkitect.php) (entry config) and its rule tiers
  [phparkitect-rules-default.php](./../configDefaults/generic/phparkitect-rules-default.php),
  [phparkitect-rules-optional.php](./../configDefaults/generic/phparkitect-rules-optional.php),
  [phparkitect-rules-optional-symfony.php](./../configDefaults/generic/phparkitect-rules-optional-symfony.php),
  and [phparkitect-consumer-api-boundary.php](./../configDefaults/generic/phparkitect-consumer-api-boundary.php)

Note: the PHPStan rule bundles (`rules-default.neon`, `rules-optional.neon`,
`rules-optional-symfony.neon`) live at the **repo root**, not under `configDefaults/generic/`.

#### Config Overrides

If no local config file exists in your project's `qaConfig` folder, PHPQA uses the config in its
own `configDefaults/` folder. Resolution is handled by
[ConfigPathResolver](./../src/Pipeline/Config/ConfigPathResolver.php) as a 3-level lookup — the first
that exists wins:

1. Your project's root `qaConfig/phpstan.neon`
2. PHPQA's `configDefaults/{platform}/phpstan.neon` (no platform folder ships, so this rung is
   normally absent)
3. PHPQA's `configDefaults/generic/phpstan.neon`

To create your own override:

1. Make a directory in your project root called `qaConfig`
2. Copy the configuration from [configDefaults/generic](./../configDefaults/generic) into your project `qaConfig` folder
3. Customise the copied config as you see fit

For example, for PHPStan:

```bash
cd /my/project/root

mkdir -p qaConfig

# Copy in the default config
cp vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon qaConfig/

# Edit the config
vim qaConfig/phpstan.neon
```

### Project configuration: `qaConfig/qa.php`

Settings that are not a tool's own config file (memory limit, ignored paths, mutation floors,
opt-outs) live in `qaConfig/qa.php`. The file returns a closure that receives the pipeline's
`QaConfigBuilder`, seeded from the defaults and the environment variables above, and returns the
adjusted builder. Every setting is a typed method, so a misspelt one fails at load time:

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withInfectionFloors(msi: 82, coveredMsi: 82)
    ->withIgnoredPaths('tests/assets');
```

The full method list, and the mapping from the Bash-era variables, is in
[upgrading-to-8.5.md](upgrading-to-8.5.md). A template ships at
`templates/qaConfig-qa.php`. This repository's own [qaConfig/qa.php](./../qaConfig/qa.php) is
the worked example.

Per-tool overrides (`qaConfig/tools/<tool>.php` returning a `ToolInterface`) and the pre/post
hooks (`qaConfig/hookPre.php`, `qaConfig/hookPost.php` returning a callable) are described on the
same page. A Bash-era `qaConfig.inc.bash`, `tools/*.inc.bash` or `hook*.bash` is refused with a
message pointing at the replacement.
