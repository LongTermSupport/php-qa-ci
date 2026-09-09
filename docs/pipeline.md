# PHPQA Pipeline

PHPQA runs tools in a sequence designed to fail as quickly as possible, making it ideal for both local development and CI.

The tools are organised into four phases. Code modification runs first (so later phases validate the final state of the code), followed by linting, static analysis, and testing. There is no point running static analysis on code that has not yet been auto-fixed, and no point running tests if the code has syntax errors.

The pipeline is PHP. `bin/qa` boots [QaApplication](../src/Pipeline/Cli/QaApplication.php), which builds the configuration, wires the services and hands a [ToolContext](../src/Pipeline/Tool/ToolContext.php) to the [Pipeline](../src/Pipeline/Runner/Pipeline.php) runner. Each tool is a lane: one `ToolInterface` class under [src/Pipeline/Lane/](../src/Pipeline/Lane/), located by name through [ShippedToolLocator](../src/Pipeline/Runner/ShippedToolLocator.php) so a project's `qaConfig/tools/<name>.php` can replace it, and run by [ToolExecutor](../src/Pipeline/Runner/ToolExecutor.php).

## Fail-fast, aggregate and retries

A writable run (the local default) is **fail-fast**: the first failing tool ends the run with exit code 1. A read-only run (`QA_READONLY=1`, and GitHub Actions by default) is **aggregate**: every tool runs, the failures are collected and reported together at the end, and the exit code is 1 if any failed. `QA_FAIL_FAST=1` forces fail-fast in a read-only run. See [Configuration](./configuration.md) for the environment variables.

In an interactive run (a TTY, `CI` unset) a failed tool prints its failure banner and asks whether to try again; you can retry indefinitely until it passes. In CI mode (`CI=true`, a Claude Code session, or no TTY) the answer is always no. A tool that **crashed** (an exit code outside its documented pass/fail set) is never offered a retry. If any tool was retried, the run ends with a warning to run the whole pipeline again.

## Hooks

Two hooks let you run project-specific tasks before the QA process commences and after it has successfully completed. They are PHP files in your project's `qaConfig` folder, `hookPre.php` and `hookPost.php`, each returning a callable that receives the `ToolContext` (the built configuration, the process runner, the PHP invoker and the console output):

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\ToolContext;

return static function (ToolContext $context): void {
    $context->writeln('warming the cache');
    $context->php->withoutXdebug('bin/console', ['cache:warmup'], $context->config->paths->projectRoot);
};
```

The pre hook runs after configuration and PHAR verification, before the run lock and the first tool. The post hook runs only after every tool passed and PHPLoc has printed its statistics. To fail the whole process from a hook, throw an exception. A Bash-era `hookPre.bash` / `hookPost.bash` is refused with a message pointing at [Upgrading to 8.5](./upgrading-to-8.5.md).

### Suggested Use Cases

#### hookPre.php

- Flushing and/or priming caches
- Building IDE helpers
- Updating composer dependencies

#### hookPost.php

- Pushing code to CI
- Rebuilding example code
- Generating reports

## The Pipeline

### 1. Detection and Configuration

`QaApplication` performs these steps in order:

 - **Arguments** ([ArgumentsParser](../src/Pipeline/Cli/ArgumentsParser.php)): `-t <tool>`, `-p <path>` (or a single bare path), `--json` (PHPStan only), `-h`. A path given to a tool that does not support paths, an unknown tool or an unknown option prints the usage and exits 1.
 - **Environment** ([EnvironmentReader](../src/Pipeline/Config/EnvironmentReader.php)): decides CI, read-only and aggregate mode and announces them.
 - **Project paths** ([ProjectPathsResolver](../src/Pipeline/Config/ProjectPathsResolver.php)): requires `src/` and `tests/` (or `test/`); reads `config.bin-dir` from `composer.json` (default `vendor/bin`); fixes `var/qa`, `var/qa/cache`, `qaConfig/` and the library's `vendor-phar/` and `configDefaults/`.
 - **Platform detection** ([PlatformDetector](../src/Pipeline/Config/PlatformDetector.php)): Symfony via `symfony.lock`, otherwise generic. See [Platform Detection](./platform-detection.md).
 - **Xdebug probe**: whether coverage and mutation testing are available.
 - **Configuration**: `QaConfigBuilder::defaults()` seeds every setting from the shipped defaults and the environment variables; [ProjectConfigLoader](../src/Pipeline/Config/ProjectConfigLoader.php) applies your `qaConfig/qa.php`; `build()` derives the dependent values (coverage needs Xdebug, Infection needs coverage). A leftover `qaConfig.inc.bash` is refused with migration guidance.

### 2. Preparation

`Pipeline::run()` then:

 - creates `var/qa/` and `var/qa/cache/` with self-excluding `.gitignore` files and adds the managed block of QA runtime-cache excludes to the project's root `.gitignore` ([DirectoryPreparer](../src/Pipeline/Runner/DirectoryPreparer.php));
 - verifies the PHARs ([PharToolsVerifier](../src/Pipeline/Runner/PharToolsVerifier.php)): `phive.xml` is a hard requirement, and every PHAR it lists plus the committed `vendor-phar/rector.phar` must be present under the library's `vendor-phar/`. Nothing is fetched at run time; PHIVE re-fetches only in the maintainer `update`/`--force` modes of `scripts/tool-install.bash`, and maintainers rebuild Rector with `scripts/build-rector-phar.bash`;
 - runs your project's `hookPre.php` if present;
 - acquires the run lock ([RunLock](../src/Pipeline/Lock/RunLock.php)). One run per project: a JSON lock file under `qaConfig/.qa-lock/` records host, pid, tool, path and last activity. A live holder aborts the run before any tool executes. A holder with no activity for 600 seconds is presumed dead (container restarts leave PIDs meaningless, so liveness is time-based) and its lock is removed with a note. The runner touches the lock before every tool so a long lane never goes stale, and releases it with the exit code and elapsed time at the end.

### 3. QA Tools (Four Phases)

The phase order and the `-t` aliases are owned by [ToolRegistry](../src/Pipeline/Tool/ToolRegistry.php) and frozen by a characterisation test.

#### Phase 1: Code Modification
1. **[Rector](./tools/rector.md)** -- Automated refactoring (safe functions, PHPUnit, PHP 8.5 upgrades)
2. **[PHP CS Fixer](./tools/phpCsFixer.md)** -- Code style fixing (runs as PHAR)

#### Phase 2: Linting and Validation
3. **[PSR-4 Validation](./tools/psr4Validate.md)** -- Namespace/directory structure compliance
4. **[Composer Checks](./tools/composerChecks.md)** -- Diagnose, normalize, dump autoloader
5. **[Package Type Declaration](./tools/packageType.md)** -- Always-on: requires `composer.json` to declare a `type`
6. **[Config Template Ignore-List Audit](./tools/configTemplateIgnoreListCheck.md)** -- Always-on self-check: every namespace-less `configDefaults/generic/` template is covered by `psr4-validate-ignore-list.txt`
7. **[Infection Config Source Directories Check](./tools/infectionConfigSourceDirs.md)** -- Always-on: infection.json's `source.directories` must resolve to real directories
8. **[Version Pins Check](./tools/versionPins.md)** -- Always-on: phpunit.xml, safe scan-files and GitHub Actions PHP pins match the toolchain in use
9. **[Strict Types Enforcement](./tools/phpStrictTypes.md)** -- Ensures `declare(strict_types=1)`
10. **[PHP Lint](./tools/phpLint.md)** -- Fast parallel syntax checking
11. **[Composer Require Checker](./tools/composerRequireChecker.md)** -- Missing dependency detection (runs as PHAR)
12. **[Markdown Links Checker](./tools/markdownLinks.md)** -- Link validation in documentation

On a Symfony project the platform lanes **[Twig Lint](./tools/twigLint.md)** and **[Yaml Lint](./tools/yamlLint.md)** follow, appended to this phase.

#### Phase 3: Static Analysis
13. **Branch Name Policy** -- Always-on: enforces the PR branch-naming convention (runs first in this phase); see [branch-policy.md](../CLAUDE/branch-policy.md)
14. **[PHPStan ignoreErrors Justification](./tools/phpstanIgnoreJustification.md)** -- Always-on: every `ignoreErrors` entry in `qaConfig/phpstan.neon` carries a justifying comment
15. **[PHPStan](./tools/phpstan.md)** -- Static analysis at level max (runs as PHAR)
16. **[PHPArkitect](./tools/phpArkitect.md)** -- Architecture rules; on by default (`withArkitect(false)` to disable, runs as PHAR)
17. **[SensitiveParameter Usage](./tools/sensitiveParameterUsage.md)** -- Always-on: fails if `#[\SensitiveParameter]` is used nowhere in `src/`

#### Phase 4: Testing
18. **[PHPUnit](./tools/phpunit.md)** -- Unit and integration tests
19. **[Infection](./tools/infection.md)** -- Mutation testing (requires Xdebug and coverage; `withInfection(false)` to disable, runs as PHAR)

PHPStan and PHPUnit are skipped when `phpqaQuickTests=1`; Infection is skipped when quick tests are on or Infection is disabled. These gates apply to phase runs, not to a single tool selected with `-t`.

To read about each tool in detail, see [PHPQA's suite of tools](./phpqa-tools.md).

### 4. Post Hook

If all tests pass, the pipeline runs your project's `hookPost.php` if present, prints the retry warning if any tool was retried, and releases the lock.

If there were retries of any of the tools, it is strongly suggested that you rerun the full pipeline before regarding it as passing.
