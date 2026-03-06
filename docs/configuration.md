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

 If you want to run only fast PHPQA tests.

##### CI Mode:
 `CI`

 Will not prompt for user input.

##### Skip uncommitted check:
 `skipUncommittedChangesCheck`

 Don't check for uncommitted changes when running.

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

The bulk of the configuration is handled with configuration files which are separated by platform.

- [configDefaults/](./../configDefaults) contains subfolders specific to each platform
    - [generic/](./../configDefaults/generic) for config files not specific to any platform

Each platform folder contains the configuration files for that platform. Where a file does not exist in the platform folder, the generic configuration file is used.

PHPQA's [configDefaults/generic](./../configDefaults/generic) folder contains a config file for each tool run by PHPQA. At the moment this includes:

- [infection.json](./../configDefaults/generic/infection.json)
- [phpstan.neon](./../configDefaults/generic/phpstan.neon)
- [phpunit.xml](./../configDefaults/generic/phpunit.xml)
- [php_cs.php](./../configDefaults/generic/php_cs.php)
- [psr4-validate-ignore-list.txt](./../configDefaults/generic/psr4-validate-ignore-list.txt)
- [composerRequireChecker.json](./../configDefaults/generic/composerRequireChecker.json)
- [rector-safe.php](./../configDefaults/generic/rector-safe.php)
- [rector-phpunit.php](./../configDefaults/generic/rector-phpunit.php)
- [rector-php84.php](./../configDefaults/generic/rector-php84.php)

#### Config Overrides

If no local config file exists in your project's `qaConfig` folder, PHPQA will detect what type of platform you're on and use the config in its own `configDefaults/` folder.

As an example, when running PHPStan on a Symfony codebase, PHPQA will check for its config in the following order:

1. Your project's root `qaConfig/phpstan.neon`
2. PHPQA's root `configDefaults/symfony/phpstan.neon`
3. PHPQA's root `configDefaults/generic/phpstan.neon`

To create your own override:

1. Make a directory in your project root called `qaConfig`
2. Copy the configuration from [configDefaults](./../configDefaults) into your project `qaConfig` folder
3. Customise the copied config as you see fit

For example, for PHPStan:

```bash
cd /my/project/root

mkdir -p qaConfig

# Copy in the default config
platform="generic" # See vendor/lts/php-qa-ci/configDefaults/ for options
cp vendor/lts/php-qa-ci/configDefaults/${platform}/phpstan.neon qaConfig/

# Edit the config
vim qaConfig/phpstan.neon
```

### Global Configuration Override

If you want to make more wholesale tweaks to `qa` customisation, you can create a file in your `qaConfig` directory called `qaConfig.inc.bash`. This will be automatically detected and included, and can then override all kinds of default configuration that has occurred up to that point.

When you run `qa`, it checks for a file located in `"$projectConfigPath/qaConfig.inc.bash"` and will include it if found.

Using this you can override as much of the standard configuration as you see fit.

**_For example, the PHPQA project itself has a [qaConfig](./../qaConfig) folder which is used when PHPQA is run against itself._**
