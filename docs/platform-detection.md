# Platform Detection

PHPQA can amend its behaviour for specific platforms.

By default there's a generic set of tools and configuration, but these can be supplanted by platform-specific versions.

## The `detectPlatform` function

The [functions include file](../includes/functions.inc.bash)'s `detectPlatform` function is run at the start of the `bin/qa` script.

It inspects the project that PHPQA is being run against, whether the project root or a specified folder.

The function checks for a single platform-specific marker:
- **Symfony**: presence of `symfony.lock`
- **Generic**: default for all other PHP projects

There is no Laravel (`artisan`) detection, and no `platformLaravel` constant — the only declared
platforms are `platformSymfony` and `platformGeneric`. If the `symfony.lock` check does not pass,
the function returns the `platformGeneric` value.

Once the `bin/qa` script captures this, it's then made available for global use.

It's used by `runTool` to find the right `includes/(platform)/(tool).inc.bash` tool to run, and by
`configPath` to look for a `configDefaults/(platform)/(configFile)` override. Only `includes/symfony/`
ships any platform overrides today; no `configDefaults/(platform)/` folder exists, so config lookups
fall through to `configDefaults/generic/`.