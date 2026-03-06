# Platform Detection

PHPQA can amend its behaviour for specific platforms.

By default there's a generic set of tools and configuration, but these can be supplanted by platform-specific versions.

## The `detectPlatform` function

The [functions include file](../includes/functions.inc.bash)'s `detectPlatform` function is run at the start of the `bin/qa` script.

It inspects the project that PHPQA is being run against, whether the project root or a specified folder.

The function checks for platform-specific markers:
- **Symfony**: presence of `symfony.lock`
- **Laravel**: presence of `artisan`
- **Generic**: default for all other PHP projects

If none of the platform checks pass, it returns the `platformGeneric` value.

Once the `bin/qa` script captures this, it's then made available for global use.

It's used by `runTool` to find the right `includes/(platform)/(tool).inc.bash` tool to run, and `configPath` to find the right `configDefaults/(platform)/(configFile)` file.