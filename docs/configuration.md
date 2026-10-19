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

Setting this to `1` **skips whole tools**, not just slow tests: every tool gated `NotQuick` or
`Infection` in its registry row (PHPStan and Dead Code Detection in Phase 3, PHPUnit and
Infection in Phase 4; see [the gates](pipeline.md#gates)) is skipped entirely. Use it for a fast lint/style/validation
pass. Do not confuse it with `phpUnitQuickTests`, which is narrower — it still runs PHPUnit but
lets individual tests take a faster path (see the PHPUnit docs).

##### CI Mode:

`CI`

Will not prompt for user input: no retry prompt and no retry loop. It is switched on
automatically in a Claude Code session (`CLAUDECODE=1`) and whenever stdin or stdout is not a
TTY. `CI` controls interactivity only; it does not decide whether the fixers may write.

##### Read-only runs:

`QA_READONLY`

Whether the mutating tools (Rector, PHP CS Fixer, PHP Strict Types and the other fixers) may
write is decided separately from `CI`: `QA_READONLY=1` (or `true`) is read-only,
`QA_READONLY=0` (or `false`) is writable; when it is unset, GitHub Actions (`GITHUB_ACTIONS=true`)
is read-only and everything else (a local terminal, a Claude Code session, cron) is writable. In
a read-only run the fixers run in dry-run mode and a pending change fails the run instead of
being applied, and the run defaults to [aggregate mode](pipeline.md#fail-fast-aggregate-and-retries)
(`QA_FAIL_FAST=1` turns that off). `CI=true` alone does not make a run read-only: to reproduce
a CI failure locally, run `QA_READONLY=1 vendor/bin/qa`.

##### Memory limit:

`phpqaMemoryLimit`

Global memory limit for all QA tools. Default: `4G`. It is applied to every PHP process the
pipeline starts, including the Xdebug-enabled coverage runs.

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
3. Check every relative path in the copy: a reference built from `__DIR__` now resolves from
   `qaConfig/`, not from `configDefaults/generic/`. For example, `php_cs.php` falls back to
   `__DIR__ . '/php_cs_finder.php'` when the project has no `qaConfig/php_cs_finder.php`, so a
   copied `php_cs.php` needs either a copied finder or that path pointed at
   `__DIR__ . '/../vendor/lts/php-qa-ci/configDefaults/generic/php_cs_finder.php'`
4. Customise the copied config as you see fit

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
adjusted builder, so a setting in `qa.php` wins over the environment. `build()` then derives the
dependent values (coverage needs Xdebug, Infection needs coverage), so a project cannot switch on
what the host cannot run. Every setting is a typed method, so a misspelt one fails at load time:

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withInfectionFloors(msi: 82, coveredMsi: 82)
    ->withIgnoredPaths('tests/assets');
```

`withIgnoredPaths()` takes project-relative paths, and every lane that scans the checked paths
skips them: Rector, PHP CS Fixer, PHP Strict Types, PHP Lint, OPcache, PSR-4 Validation, PHPStan,
Dead Code Detection, PHPArkitect, Infection and PHPCPD. ShellCheck and Analysed Paths honour them
too. Each lane's page under [tools/](tools/) says how it hands them to its tool, so the path is
not repeated in any tool's own config. Lanes whose scope is not the checked paths (the Composer
checks, the Twig and YAML lanes, the Markdown lanes, PHPUnit) do not read the setting.

The full method list, and the mapping from the Bash-era variables, is in
[upgrading-to-8.5.md](upgrading-to-8.5.md). A template ships at
`templates/qaConfig-qa.php`. This repository's own [qaConfig/qa.php](./../qaConfig/qa.php) is
the worked example.

Adding tools and phases of your own is a separate file, `qaConfig/pipeline.php`, returning a
closure over `PipelineBuilder`; see [extending the pipeline](extending-the-pipeline.md).

Per-tool overrides (`qaConfig/tools/<tool>.php` returning a `ToolInterface`) and the pre/post
hooks (`qaConfig/hookPre.php`, `qaConfig/hookPost.php` returning a callable) are described on the
same page. A Bash-era `qaConfig.inc.bash`, `tools/*.inc.bash` or `hook*.bash` is refused with a
message pointing at the replacement.

## The pipeline is not a tool proxy

`vendor/bin/qa` enforces one standard configuration per tool: the same PHPStan level and the same
PHP CS Fixer rules in every project, tools run in a fixed order, PHARs and cache directories
managed for you, and the same Xdebug and memory settings everywhere. It does not pass arbitrary
arguments through to the tools, so `vendor/bin/qa -t stan --help` is not a PHPStan help screen.

What it does take is a tool and a path, so a run can be narrowed without changing how the tool
is configured:

```bash
vendor/bin/qa -t stan -p src
vendor/bin/qa -t fixer -p src/Domain
vendor/bin/qa -p tests/Unit
```

When a tool needs something the pipeline does not offer:

- for a lasting change, put it in the tool's config file under `qaConfig/` or in `qaConfig/qa.php`
  (above);
- for a one-off run with custom flags, call the tool directly, for example
  `vendor/bin/phpstan analyse --help`;
- for a custom workflow, write a wrapper script that calls the tools directly.

A development script (a `docker.bash`, a CI wrapper) should call `vendor/bin/qa -t <tool>` with
`-p` for standard runs and fall back to calling a tool directly only when it genuinely needs a
flag the pipeline does not offer.
