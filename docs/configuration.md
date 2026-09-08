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

# Or in qaConfig/qaConfig.inc.bash
export phpqaMemoryLimit=8G
```

## Configuration Files

The bulk of the configuration is handled with configuration files under
[configDefaults/](./../configDefaults). Only a `generic/` folder ships today — there are no
per-platform config folders. The `configPath()` resolver still supports a platform rung
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
own `configDefaults/` folder. Resolution is handled by `configPath()`
([includes/functions.inc.bash](./../includes/functions.inc.bash)) as a 3-level lookup — the first
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

### Global Configuration Override

If you want to make more wholesale tweaks to `qa` customisation, you can create a file in your `qaConfig` directory called `qaConfig.inc.bash`. This will be automatically detected and included, and can then override all kinds of default configuration that has occurred up to that point.

When you run `qa`, it checks for a file located in `"$projectConfigPath/qaConfig.inc.bash"` and will include it if found.

Using this you can override as much of the standard configuration as you see fit.

**_For example, the PHPQA project itself has a [qaConfig](./../qaConfig) folder which is used when PHPQA is run against itself._**
