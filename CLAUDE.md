# PHP-QA-CI Library Documentation

## Knowledge & Memory Policy (binding)

Persistent Claude memory is DISABLED for this project — never write to the
harness memory store (`~/.claude/projects/*/memory/`). ALL knowledge, memory
and context MUST be tracked in-repo, clean of secrets: durable operational
knowledge in `CLAUDE/*.md` (e.g. [CLAUDE/prepush-verification.md](CLAUDE/prepush-verification.md)
— the mandatory pre-push battery; pushing `php8.5` deploys to production),
programme/work records in `CLAUDE/Plan/`.

## Working on php-qa-ci from a consuming project's `vendor/` (dogfooding)

php-qa-ci is frequently installed **from source** into a consuming project, so
`vendor/lts/php-qa-ci/` is a real git checkout with its own `.git`. When a harness
change is needed while working inside the consumer, work on it in place — no separate
clone required:

- **Edit and commit in the vendored checkout.** Change the include/config/test under
  `vendor/lts/php-qa-ci/` and `git commit` there. Pushing follows the consuming
  project's policy for `lts/*` (typically: commit locally, a human pushes/releases).

- **Dogfood against real consumer code.** The vendored copy is the live copy the
  consumer's `vendor/bin/qa` runs from, so the consumer's next QA run exercises the
  change with no reinstall.

- **Also pass php-qa-ci's OWN battery before any push.** php-qa-ci ships its own
  `composer.json` + `composer.lock` + `qaConfig/`, so run its own QA against its own
  `src/`/`tests/`:

  ```bash
  cd vendor/lts/php-qa-ci
  composer install
  QA_READONLY=1 CI=true bin/qa        # full read-only battery (the real pre-push gate)
  CI=true bin/qa -t unit              # or a single tool while iterating
  ```

  A consumer's `bin/qa` validates the CONSUMER's code; the commands above validate
  php-qa-ci itself (its `Large` include-level tests, PHPStan, Rector/CS-Fixer
  dry-run). This is the battery [CLAUDE/prepush-verification.md](CLAUDE/prepush-verification.md)
  mandates before a `php8.5` push. The nested `vendor/lts/php-qa-ci/vendor/` from
  `composer install` is the package's own dev environment, isolated from the
  consumer's tree.

## Overview

PHP-QA-CI is a quality assurance pipeline for PHP projects. It orchestrates the standard PHP
quality tools in a fixed sequence designed to fail fast and give rapid feedback. The pipeline
itself is PHP: `bin/qa` is a PHP entrypoint and every lane is a class under `src/Pipeline/`.
Bash survives only as thin wrappers (`ci.bash`, the `bin/` redirect stubs) and the maintainer
scripts under `scripts/`.

## Architecture

### Core Components

1. **Entrypoint**: `bin/qa` boots [src/Pipeline/Cli/QaApplication.php](src/Pipeline/Cli/QaApplication.php), the composition root that parses arguments, reads the environment, builds the configuration and runs the pipeline
2. **Tool registry**: [src/Pipeline/Tool/ToolRegistry.php](src/Pipeline/Tool/ToolRegistry.php) is the single source of truth for tool names, `-t` aliases, phase membership and order, path support, gates and banners; the CLI usage text is derived from it
3. **Lanes**: one `ToolInterface` class per tool under [src/Pipeline/Lane/](src/Pipeline/Lane/), wired by name in [src/Pipeline/Tool/ShippedTools.php](src/Pipeline/Tool/ShippedTools.php)
4. **Runner**: [src/Pipeline/Runner/Pipeline.php](src/Pipeline/Runner/Pipeline.php) walks the phases and applies the gate, retry and aggregate policies; [src/Pipeline/Runner/ToolExecutor.php](src/Pipeline/Runner/ToolExecutor.php) runs one lane with the retry prompt
5. **Configuration**: a typed, immutable [QaConfigBuilder](src/Pipeline/Config/QaConfigBuilder.php) seeded from defaults and environment variables, adjusted by the project's `qaConfig/qa.php`; config files resolve through [ConfigPathResolver](src/Pipeline/Config/ConfigPathResolver.php)
6. **Platform detection**: [PlatformDetector](src/Pipeline/Config/PlatformDetector.php) recognises Symfony (via `symfony.lock`); everything else is generic

### How It Works

When you run `vendor/bin/qa` in your project:

1. `bin/qa` locates the project's `vendor/autoload.php`; the project root is its parent directory and the library root is the directory `bin/qa` lives in
2. `QaApplication` parses the arguments, reads the environment, resolves the project paths and detects the platform
3. The configuration is built from the shipped defaults, then adjusted by `qaConfig/qa.php` if present
4. Tools run from your project's bin directory and the library's `vendor-phar/` (never from php-qa-ci's own `vendor/`)
5. The four phases execute in a fixed order designed to modify code first, then validate it

**Note on bin directory location**: The qa script is installed in the directory specified by the `bin-dir` config in your composer.json. By default this is `vendor/bin`, but it can be configured to any directory (e.g., `bin`). All examples in this documentation assume the default `vendor/bin` location.

## Pipeline Execution Order

### Preflight Phase (Configuration & Setup)

Before any tool runs, `QaApplication` and `Pipeline` perform these steps in order:

01. **Arguments** ([ArgumentsParser](src/Pipeline/Cli/ArgumentsParser.php)) - `-t <tool>`, `-p <path>` (or a single bare path), `--json`, `-h`. A path given to a tool that does not support paths, an unknown tool, or an unknown option prints the usage and exits 1

02. **Environment** ([EnvironmentReader](src/Pipeline/Config/EnvironmentReader.php)) - typed access to `CI`, `QA_READONLY`, `QA_FAIL_FAST`, `phpqaMemoryLimit`, `PHP_QA_CI_PHP_EXECUTABLE` and the tool variables; decides the CI, read-only and aggregate modes and announces them

03. **Project paths** ([ProjectPathsResolver](src/Pipeline/Config/ProjectPathsResolver.php)) - requires `src/` and `tests/` (or `test/`); reads `config.bin-dir` from composer.json (default `vendor/bin`); fixes `var/qa`, `var/qa/cache`, `qaConfig/`, the library's `vendor-phar/` and `configDefaults/`

04. **Platform detection** ([PlatformDetector](src/Pipeline/Config/PlatformDetector.php)) - Symfony when `symfony.lock` exists, otherwise generic

05. **Xdebug probe** ([PhpInvoker](src/Pipeline/Process/PhpInvoker.php)) - determines whether coverage and mutation testing are available; sets `XDEBUG_MODE=coverage` unless it is already `debug`

06. **Configuration** - `QaConfigBuilder::defaults(...)` seeds every setting from the environment; [ProjectConfigLoader](src/Pipeline/Config/ProjectConfigLoader.php) applies `qaConfig/qa.php`; `build()` then derives the dependent values (coverage needs Xdebug, Infection needs coverage) so a project cannot switch on what the host cannot run. A leftover `qaConfig.inc.bash` is refused with migration guidance

07. **Directories** ([DirectoryPreparer](src/Pipeline/Runner/DirectoryPreparer.php)) - creates `var/qa/` and `var/qa/cache/` with self-excluding `.gitignore` files and adds the managed block of QA runtime-cache excludes to the project's root `.gitignore`

08. **PHAR verification** ([PharToolsVerifier](src/Pipeline/Runner/PharToolsVerifier.php)) - `phive.xml` is a hard requirement; every PHAR it lists plus `vendor-phar/rector.phar` must be present under the library's `vendor-phar/`, otherwise the run fails with the missing names. Nothing is fetched at run time (PHIVE only re-fetches in the maintainer `update`/`--force` modes of `scripts/tool-install.bash`). Rector is the committed, self-built `vendor-phar/rector.phar`; maintainers rebuild it with `scripts/build-rector-phar.bash` (see [CLAUDE/Plan/Completed/00002-phar-vendored-rector](CLAUDE/Plan/Completed/00002-phar-vendored-rector/PLAN.md))

09. **Pre-hook** ([HookRunner](src/Pipeline/Runner/HookRunner.php)) - runs `qaConfig/hookPre.php` if present

10. **Run lock** ([RunLock](src/Pipeline/Lock/RunLock.php)) - one run per project. A JSON lock file under `qaConfig/.qa-lock/` records host, pid, tool, path and last activity. A live holder aborts the run (exit 1) before any tool executes; a holder quiet for longer than the stale window (600 seconds) is presumed dead, removed with a note, and the lock is taken. The runner touches the lock before every tool so a long lane never goes stale. Liveness is time-based, not PID-based, because container restarts make PIDs meaningless

Only after all preflight steps complete does tool execution begin.

### Main Tool Execution Phases

The pipeline runs tools in 4 distinct phases, each lane being a class under `src/Pipeline/Lane/`:

### Phase 1: Coding Standards Tools (can modify code)

1. **Rector** (`rector`) - Automated refactoring and code upgrades
2. **PHP CS Fixer** (`phpCsFixer`) - Code style fixing

On a Symfony project the platform lane **Twig CS Fixer** (`twigCsFixer`) is appended to this phase; it is not `-t` selectable.

### Phase 2: Linting Tools (validation only)

03. **PSR-4 Validation** (`psr4Validate`) - Validates namespace/directory structure
04. **Composer Checks** (`composerChecks`) - Runs composer diagnose, normalize and dump-autoload
05. **Package Type Declaration** (`packageType`) - Always-on: requires `composer.json` to declare a `type` (see [docs/tools/packageType.md](docs/tools/packageType.md))
06. **Config Template Ignore-List Audit** (`configTemplateIgnoreList`) - Always-on self-check: every namespace-less `configDefaults/generic/` template must be covered by `psr4-validate-ignore-list.txt` (see [docs/tools/configTemplateIgnoreListCheck.md](docs/tools/configTemplateIgnoreListCheck.md))
07. **Infection Config Source Directories Check** (`infectionConfigSourceDirs`) - Always-on: infection.json's `source.directories` entries must resolve, relative to infection.json's own directory, to real directories (see [docs/tools/infectionConfigSourceDirs.md](docs/tools/infectionConfigSourceDirs.md))
08. **Version Pins Check** (`versionPins`) - Always-on: phpunit.xml, safe scan-files and GitHub Actions PHP pins match the toolchain in use (see [docs/tools/versionPins.md](docs/tools/versionPins.md))
09. **Strict Types Enforcement** (`phpStrictTypes`) - Ensures `declare(strict_types=1)` in all PHP files
10. **PHP Lint** (`phpLint`) - Fast parallel syntax checking
11. **Composer Require Checker** (`composerRequireChecker`) - Checks for missing dependencies
12. **Composer Dependency Analyser** (`composerDependencyAnalyser`) - Checks for unused, shadow and misplaced dependencies (see [docs/tools/composerDependencyAnalyser.md](docs/tools/composerDependencyAnalyser.md))
13. **Markdown Links Checker** (`markdownLinks`) - Validates links in markdown files

On a Symfony project the platform lanes **Twig Lint** (`twigLint`) and **Yaml Lint** (`yamlLint`) are appended to this phase. They are not `-t` selectable.

### Phase 3: Static Analysis Tools

14. **Branch Name Policy** (`branchNamePolicy`) - Runs first in this phase. Always-on: enforces the PR branch-naming convention (see [CLAUDE/branch-policy.md](CLAUDE/branch-policy.md))
15. **PHPStan ignoreErrors Justification** (`phpstanIgnoreJustification`) - Always-on: every `ignoreErrors` entry in `qaConfig/phpstan.neon` must carry a comment naming the hazard accepted and its scope (see [docs/tools/phpstan.md](docs/tools/phpstan.md#suppressing-errors))
16. **PHPStan** (`phpstan`) - Static analysis tool
17. **PHPArkitect** (`phpArkitect`) - Architecture rules (class naming, namespace layering, dependency direction). On by default; applies a generic-safe baseline and is composable/overridable per project. Opt out with `withArkitect(false)` in `qaConfig/qa.php` or `useArkitect=0` in the environment. See the [PHPArkitect section in README.md](README.md#phparkitect-architecture-rules).
18. **SensitiveParameter Usage** (`sensitiveParameterUsage`) - Always-on security baseline: fails if `#[\SensitiveParameter]` is used nowhere in `src/`. Opt out per-project with `withSensitiveParameterCheck(false)`.

### Phase 4: Testing Tools

19. **PHPUnit** (`phpunit`) - Unit testing framework
20. **Infection** (`infection`) - Mutation testing (requires Xdebug and coverage; `withInfection(false)` or `useInfection=0` to disable)

**Gates**: PHPStan and PHPUnit are skipped when `phpqaQuickTests=1`; Infection is skipped when quick tests are on or Infection is disabled ([ToolGateEnum](src/Pipeline/Tool/ToolGateEnum.php)). Gates apply to phase runs, not to a single tool selected with `-t`.

### Post-Success Phase (After all tests pass)

After the "ALL TESTS PASSING" message:

21. **PHPCPD** (`phpcpd`) - Copy/paste detection over the checked paths

    - Informational only and cannot fail the pipeline, because duplication is a judgement call rather than a defect
    - Writes a JSON report to `var/qa/phpcpd/phpcpd.json` on every run
    - See [docs/tools/phpcpd.md](docs/tools/phpcpd.md)

22. **Post-Hook** (`qaConfig/hookPost.php`) - Runs the project's post-pipeline callable if present

    - Only runs if all previous tools passed
    - Common uses: generate reports, notifications, cleanup

### Final Steps

- **Retry Warning** - If any tools were retried during the run, displays a warning
- **Lock release** - the lock file is removed and the elapsed time printed
- **Completion Message** - Shows hostname and completion status

## Configuration System

### Configuration Cascade

Config **files** are resolved by [ConfigPathResolver](src/Pipeline/Config/ConfigPathResolver.php),
a 3-level lookup — the first that exists wins:

1. **Project override** — `{project}/qaConfig/{relativePath}` (e.g. `qaConfig/phpstan.neon`)
2. **Platform default** — `configDefaults/{platform}/{relativePath}` — only `generic` ships,
   so this rung is normally absent and the lookup falls through
3. **Generic default** — `configDefaults/generic/{relativePath}` (e.g. `php_cs.php`,
   `phpstan.neon`)

The generic path is returned even when it does not exist, so a lane can report the path it
looked for. Lanes call `$context->configPath('phpstan.neon')` on their
[ToolContext](src/Pipeline/Tool/ToolContext.php).

Config **values** cascade through the builder: `QaConfigBuilder::defaults()` seeds every setting
from the environment variables (`phpqaMemoryLimit`, `phpUnitCoverage`, `useInfection`,
`mutationScoreIndicator`, `coveredCodeMSI`, `useArkitect`, `useSensitiveParameterCheck`, ...),
then `{project}/qaConfig/qa.php` receives the builder and returns an adjusted copy, so it wins
over the environment. `build()` applies the derivations afterwards (coverage needs Xdebug,
Infection needs coverage), so a project's overrides take effect without being able to enable
what the host cannot run. Every setting is a typed `with*()` method; the full list and the
mapping from each environment variable is in
[docs/upgrading-to-8.5.md](docs/upgrading-to-8.5.md). Per-tool overrides live in
`{project}/qaConfig/tools/{toolName}.php` (see "Tool Runner System").

### Read-Only / CI Verification Mode

Whether the mutating tools (Rector, PHP CS Fixer, Strict Types) may WRITE is governed by the
read-only flag, which is **orthogonal to `CI`**:

- **`CI`** controls interactivity only (no prompts, no retry loops). It is auto-enabled for
  Claude Code (`CLAUDECODE=1`) and non-TTY shells. `CI=true` does **not** make a run read-only.
- **Read-only** controls writes, decided by `EnvironmentReader::isReadOnly()`:
  `QA_READONLY=1`/`true` → read-only; `QA_READONLY=0`/`false` → writable; else GitHub Actions
  (`GITHUB_ACTIONS=true`) → read-only; everything else (local TTY, Claude sessions, cron) →
  writable. In a read-only run the fixers run `--dry-run` and a pending change FAILS the gate
  instead of being applied. **To reproduce a CI failure locally, run `QA_READONLY=1 vendor/bin/qa`,
  not `CI=true vendor/bin/qa`.**

**Aggregate mode**: a read-only run also defaults to aggregate (non-fail-fast) mode, collecting
every failing tool in one pass and reporting them together at the end
([AggregateReport](src/Pipeline/Runner/AggregateReport.php)) rather than stopping at the first.
Force it off with `QA_FAIL_FAST=1`. `--json` (PHPStan only) sends the structured output to the
real stdout and every line of decoration to stderr.

### Key Configuration Variables

Environment variables are read once by `EnvironmentReader`; `"1"`/`"true"` and `"0"`/`"false"`
are the accepted boolean spellings. Defaults:

| Variable                                        | Default              | Builder method                                                                                                     |
| ----------------------------------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `PHP_QA_CI_PHP_EXECUTABLE`                      | `php`                | (none: PHP binary for every tool)                                                                                  |
| `phpqaQuickTests`                               | `0`                  | (none: skips PHPStan, PHPUnit and Infection)                                                                       |
| `phpUnitQuickTests`                             | `0`                  | (none: passed through to the test suite)                                                                           |
| `phpUnitCoverage`                               | `1`                  | `withPhpUnitCoverage(bool)`                                                                                        |
| `phpUnitIterativeMode`                          | `0`                  | `withPhpUnitIterativeMode(bool)` (the `uniterate` pseudo-tool)                                                     |
| `useInfection`                                  | `1`                  | `withInfection(bool)`                                                                                              |
| `mutationScoreIndicator` / `coveredCodeMSI`     | `60` / `80`          | `withInfectionFloors(int, int)`                                                                                    |
| `infectionThreads`                              | half the CPU threads | `withInfectionThreads(int)`                                                                                        |
| `infectionDiffBase` / `infectionDiffCoveredMsi` | unset / `100`        | `withInfectionDiffBase(?string, int)`                                                                              |
| `useComposerAudit`                              | `1`                  | `withComposerAudit(bool)`                                                                                          |
| (none)                                          | all floors off       | `withTypeCoverageFloors(?int $returnType, ?int $paramType, ?int $propertyType, ?int $constantType, ?int $declare)` |
| `useArkitect`                                   | `1`                  | `withArkitect(bool)`                                                                                               |
| `useSensitiveParameterCheck`                    | `1`                  | `withSensitiveParameterCheck(bool)`                                                                                |
| `CI`                                            | `false`              | (none: interactivity)                                                                                              |

### Memory Configuration

The pipeline provides a global memory limit that applies to all QA tools (default: 4G). It is
applied by [PhpInvoker](src/Pipeline/Process/PhpInvoker.php) to every PHP process the pipeline
starts, including the Xdebug-enabled coverage runs.

**How to Override**:

```php
// In qaConfig/qa.php (project-level):
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa->withMemoryLimit('8G');
```

```bash
# Or via environment variable:
phpqaMemoryLimit=2G vendor/bin/qa
```

## Platform Detection

[PlatformDetector](src/Pipeline/Config/PlatformDetector.php) checks for:

- **Symfony**: Presence of `symfony.lock` file
- **Generic**: Default for all other PHP projects (anything without `symfony.lock`)

There is no Laravel/`artisan` detection. A platform contributes extra lanes through
`ToolRegistry::platformLanes()`: Symfony appends `twigCsFixer` to the coding-standards phase, and `twigLint` and `yamlLint` to the linting phase.
Their directories default to `templates/` and `config/` and are set with `withTwigDirectories()`
and `withYamlDirectories()` in `qaConfig/qa.php`. See [docs/platform-detection.md](docs/platform-detection.md).

## Tool Runner System

[ToolExecutor](src/Pipeline/Runner/ToolExecutor.php) runs one lane at a time:

1. [ShippedToolLocator](src/Pipeline/Runner/ShippedToolLocator.php) resolves the lane by its canonical name:

   - `{project}/qaConfig/tools/{toolName}.php` (project override: the file returns a `ToolInterface`)
   - the shipped lane from `ShippedTools::all()`

   A Bash-era `tools/{toolName}.inc.bash` is refused with migration guidance rather than ignored.

2. The lane's `run(ToolContext)` prints its own detail to the context's output and returns a
   [ToolResultDto](src/Pipeline/Tool/Dto/ToolResultDto.php): passed, failed, crashed or skipped.
   A lane never calls `exit`, and a failing lane ends with its stable identifier
   (`phpqaci.<lane>`, resolved by `vendor/bin/rule-doc`).

3. On a **failure** the executor prints the failure banner and, in an interactive run,
   asks "try again? (y/n)" ([ConsoleRetryPrompt](src/Pipeline/Runner/ConsoleRetryPrompt.php));
   in CI mode the answer is always no. A **crash** (a tool exit code outside its documented
   pass/fail set) is never retried.

The retry, aggregate and exit-code policies belong to the runner, not to the lanes.

## PHP 8.5 Compatibility (php8.5 branch)

- **Code style is PHP CS Fixer only** - there is no PHP_CodeSniffer in the pipeline
- **PHP CS Fixer** runs the `@PHP8x5Migration` ruleset (cumulative over the 8.4 set)
- **Nullable type rules** `nullable_type_declaration_for_default_null_value` and `nullable_type_declaration` are on, for PHP 8.4+'s deprecation of implicit nullable parameters
- **Rector** runs `LevelSetList::UP_TO_PHP_85` via `rector-php85.php`
- **PHP CS Fixer 3.95+** supports PHP 8.5 natively

### PHP 8.5 Specific Configuration

```php
// In configDefaults/generic/php_cs.php
'@PHP8x5Migration' => true,
'nullable_type_declaration_for_default_null_value' => true,
'nullable_type_declaration' => ['syntax' => 'question_mark'],
```

## Hook System

The pipeline provides multiple extension points for customization:

### Built-in Hooks

- `qaConfig/hookPre.php` - Runs after configuration and PHAR verification, before the lock and the first tool
- `qaConfig/hookPost.php` - Runs after all tools complete successfully (after PHPLoc)

Each file returns a callable that receives the [ToolContext](src/Pipeline/Tool/ToolContext.php).
To fail the run from a hook, throw. A Bash-era `hookPre.bash` / `hookPost.bash` is refused with
migration guidance.

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\ToolContext;

return static function (ToolContext $context): void {
    $context->writeln('warming the cache');
    $context->php->withoutXdebug('bin/console', ['cache:warmup'], $context->config->paths->projectRoot);
};
```

The post-hook only executes if the entire pipeline succeeds. This makes it ideal for:

- Generating coverage reports
- Sending notifications
- Updating documentation
- Deploying artifacts
- Custom metrics collection

### Per-Tool Overrides

Each tool can be completely replaced by creating:

- `qaConfig/tools/{toolName}.php` - returns a `ToolInterface` that replaces the shipped lane

This allows for arbitrary customization of any tool's behavior, including:

- Changing command-line arguments
- Adding pre/post processing
- Completely replacing the tool with custom logic
- Conditionally skipping tools based on custom criteria

Example override:

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

return new class implements ToolInterface {
    public function name(): string
    {
        return 'phpstan';
    }

    public function identifier(): string
    {
        return 'myproject.phpstan';
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $context->writeln('Running custom PHPStan with project-specific rules');
        $result = $context->php->withoutXdebug(
            $context->config->paths->pharDir . '/phpstan.phar',
            ['analyse', '--configuration=custom-phpstan.neon', '--level=8', ...$context->config->pathsToCheck],
            $context->config->paths->projectRoot,
        );

        return $result->succeeded() ? ToolResultDto::passed() : ToolResultDto::failed('PHPStan reported errors');
    }
};
```

A tool prints through `$context->writeln()` and runs commands through `$context->php` (PHP
scripts and PHARs, with the no-Xdebug ini and the memory limit applied) or `$context->processes`
(any other command). It never calls `exit` or builds a shell string.

### Claude Code Hooks

PHP-QA-CI includes Claude Code hooks that provide guardrails and automation when using Claude Code for development:

**Included Hooks**:

- `php-qa-ci__auto-continue.py` - Reduces confirmation prompts (✅ recommended for all projects)
- `php-qa-ci__prevent-destructive-git.py` - Blocks commands that destroy uncommitted changes (✅ critical safety)
- `php-qa-ci__discourage-git-stash.py` - Discourages git stash with escape hatch (⚠️ optional)
- `php-qa-ci__block-plan-time-estimates.py` - Prevents time estimates in plan documents (⚠️ optional)
- `php-qa-ci__validate-claude-readme-content.py` - Ensures docs contain instructions, not logs (⚠️ optional)
- `php-qa-ci__enforce-markdown-organization.py` - Enforces doc organization (⚠️ optional, opinionated)

**Deployment**:

```bash
# Deploy all hooks, agents, and skills to your project
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

This will:

- Copy hooks to `.claude/hooks/`
- Make them executable
- Register them in `.claude/settings.json`

**Documentation**: See `.claude/hooks/README.md` for detailed hook documentation including:

- What each hook does
- When to use each hook
- Configuration options
- Testing and troubleshooting
- Hook architecture and format

**Recommendation**: Always deploy `php-qa-ci__auto-continue.py` and `php-qa-ci__prevent-destructive-git.py` by default. Evaluate others based on team standards.

**Migration**: Projects with old hook names (without `php-qa-ci__` prefix) will be automatically migrated during `composer install/update`. The deployment script updates `.claude/settings.json` to reference the new hook names.

## Managed Source

php-qa-ci can generate small PHP artefacts into a consumer's own production
namespace (a locked `<RootNs>\PhpQaCi\` tree), regenerated on every composer
install/update and drift-checked via `bin/managed-source check`. First artefact:
the `FactorySealedBy` attribute. See [CLAUDE/managed-source.md](CLAUDE/managed-source.md).

## Environment Requirements

- Linux/Unix environment (uses bash)
- PHP 8.5 or higher on this branch (`composer.json` requires `^8.5`; the `php8.5` branch targets PHP 8.5, while the separate `php8.4` and `php8.3` branches support PHP 8.4 and 8.3)
- Composer-installed project with php-qa-ci as a dependency
- Your project's composer.json must allow the `ergebnis/composer-normalize` plugin:
  ```json
  {
      "config": {
          "allow-plugins": {
              "ergebnis/composer-normalize": true
          }
      }
  }
  ```

### Custom PHP Executable

You can specify which PHP binary to use via the `PHP_QA_CI_PHP_EXECUTABLE` environment variable:

```bash
# Use specific PHP version (assuming default vendor/bin location)
PHP_QA_CI_PHP_EXECUTABLE=/usr/bin/php8.5 vendor/bin/qa

# Or export for the session
export PHP_QA_CI_PHP_EXECUTABLE=/usr/bin/php8.5
vendor/bin/qa
```

This is useful when:

- Running multiple PHP versions on the same system
- Testing compatibility across PHP versions
- Using custom PHP builds

## Common Customizations

### Override a Specific Tool

Create `qaConfig/tools/{toolName}.php` returning a `ToolInterface` (see "Per-Tool Overrides"
under "Hook System" for a complete example). The override receives the same `ToolContext` as
the shipped lane: the built configuration, the config-path resolver, the process runner and
the PHP invoker.

### Skip Specific Tools

In `qaConfig/qa.php`:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    // Skip infection testing
    ->withInfection(false);
```

The environment variable form (`useInfection=0 vendor/bin/qa`) still works for a single run.

### Add Custom Paths

In `qaConfig/qa.php`:

```php
return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withCheckedPaths('custom/path')
    ->withIgnoredPaths('tests/assets', 'src/Generated');
```

Paths are project-relative. `withCheckedPaths()` appends to the default `tests/` + `src/` set;
`-p <path>` on the command line replaces that set for one run.

## Overriding Tool Configurations

To override any tool's default configuration:

1. **Copy the default config** from `vendor/lts/php-qa-ci/configDefaults/generic/` to your `qaConfig/` directory
2. **Update relative paths** - Change paths like `__DIR__` or relative references to work from your project root
3. **Customize as needed** - Modify rules, paths, and settings

Example for PHP CS Fixer:

```bash
# Copy default config
cp vendor/lts/php-qa-ci/configDefaults/generic/php_cs.php qaConfig/

# Edit qaConfig/php_cs.php
# Change: $finderPath = __DIR__ . '/php_cs_finder.php';
# To:     $finderPath = __DIR__ . '/../vendor/lts/php-qa-ci/configDefaults/generic/php_cs_finder.php';
```

**Warning**: Always check and update relative paths when copying configs!

## Tools Reference

Every lane prints a stable identifier (`phpqaci.<lane>`) when it fails; `vendor/bin/rule-doc <identifier>` resolves it to its documentation page under [docs/tools/](docs/tools/).

### Rector

- **Purpose**: Automated refactoring and code upgrades
- **Lane**: [src/Pipeline/Lane/RectorTool.php](src/Pipeline/Lane/RectorTool.php)
- **Default**: [configDefaults/generic/rector-safe.php](configDefaults/generic/rector-safe.php)
- **How it works**: Parses PHP code into AST, applies transformation rules, writes back modified code. Runs the safe, PHPUnit and (absent a project `rector.php`) PHP 8.5 configs in turn; `--dry-run` in a read-only run
- **Key features**:
  - Upgrades code to newer PHP versions
  - Applies coding standards automatically
  - Can be configured with custom rules
- **Details**: [docs/tools/rector.md](docs/tools/rector.md)

### PHP CS Fixer

- **Purpose**: Automatically fixes code style issues
- **Lane**: [src/Pipeline/Lane/PhpCsFixerTool.php](src/Pipeline/Lane/PhpCsFixerTool.php)
- **Default**: [configDefaults/generic/php_cs.php](configDefaults/generic/php_cs.php)
- **Finder**: [configDefaults/generic/php_cs_finder.php](configDefaults/generic/php_cs_finder.php)
- **How it works**: Tokenizes PHP files, applies formatting rules, writes back formatted code; `--dry-run` in a read-only run, where a pending fix fails the gate
- **Key features**:
  - Supports PSR-12, Symfony, and custom standards
  - Can run risky rules that change code behavior
  - Highly configurable with 200+ rules
- **Details**: [docs/tools/phpCsFixer.md](docs/tools/phpCsFixer.md)

### PSR-4 Validate

- **Purpose**: Ensures namespace/directory structure compliance with PSR-4
- **Lane**: [src/Pipeline/Lane/Psr4ValidateTool.php](src/Pipeline/Lane/Psr4ValidateTool.php) (calls the validator in-process; `bin/psr4-validate` remains for standalone use)
- **Ignore list**: [configDefaults/generic/psr4-validate-ignore-list.txt](configDefaults/generic/psr4-validate-ignore-list.txt)
- **How it works**: Reads composer.json autoload definitions, checks each PHP file's namespace matches its directory location
- **Key features**:
  - Validates both psr-4 and psr-0 autoloading
  - Supports ignore patterns for legacy code
- **Details**: [docs/tools/psr4Validate.md](docs/tools/psr4Validate.md)

### Composer Checks

- **Purpose**: Validates composer configuration and dependencies
- **Lane**: [src/Pipeline/Lane/ComposerChecksTool.php](src/Pipeline/Lane/ComposerChecksTool.php)
- **Requirements**:
  - `ergebnis/composer-normalize` plugin must be allowed in YOUR PROJECT's composer.json
- **How it works**:
  - Checks if `ergebnis/composer-normalize` plugin is allowed
  - Runs `composer diagnose` to check for issues (informational, never fails the run)
  - Runs `composer normalize` to normalize composer.json (`--dry-run` in a read-only run, where a pending change fails the gate)
  - Runs `composer dump-autoload` to ensure autoloading works
- **Required in your project's composer.json**:
  ```json
  {
      "config": {
          "allow-plugins": {
              "ergebnis/composer-normalize": true
          }
      }
  }
  ```
  After adding, run: `composer update nothing`
- **Details**: [docs/tools/composerChecks.md](docs/tools/composerChecks.md)

### Package Type Declaration

- **Purpose**: Requires `composer.json` to declare an explicit `type` (Composer silently defaults an omitted `type` to `library`, which changes how the API-surface PHPStan rules behave)
- **Lane**: [src/Pipeline/Lane/PackageTypeTool.php](src/Pipeline/Lane/PackageTypeTool.php) (in-process; `bin/package-type-check` remains for standalone use)
- **When it runs**: Always-on, Phase 2, immediately after Composer Checks
- **Details**: [docs/tools/packageType.md](docs/tools/packageType.md)

### Config Template Ignore-List Audit

- **Lane**: [src/Pipeline/Lane/ConfigTemplateIgnoreListTool.php](src/Pipeline/Lane/ConfigTemplateIgnoreListTool.php)
- **Purpose**: every namespace-less template under `configDefaults/generic/` is covered by `psr4-validate-ignore-list.txt`, so the documented copy-override never fails `psr4Validate`
- **Identifier**: `phpqaci.configTemplateIgnoreList`
- **Details**: [docs/tools/configTemplateIgnoreListCheck.md](docs/tools/configTemplateIgnoreListCheck.md)

### Infection Config Source Directories Check

- **Lane**: [src/Pipeline/Lane/InfectionConfigSourceDirsTool.php](src/Pipeline/Lane/InfectionConfigSourceDirsTool.php)
- **Purpose**: every entry in infection.json's `source.directories` resolves, relative to infection.json's own directory, to a real directory
- **Identifier**: `phpqaci.infectionConfigSourceDirs`
- **Details**: [docs/tools/infectionConfigSourceDirs.md](docs/tools/infectionConfigSourceDirs.md)

### Version Pins Check

- **Lane**: [src/Pipeline/Lane/VersionPinsTool.php](src/Pipeline/Lane/VersionPinsTool.php) (in-process, handed the same resolved phpunit.xml and composerRequireChecker.json the phpunit and cr lanes run with)
- **Purpose**: every version pin in the QA configuration matches the toolchain in use: phpunit.xml's schema URL and `SYMFONY_PHPUNIT_VERSION` against the installed PHPUnit major, composer-require-checker's `thecodingmachine/safe` scan-files against the generated files safe loads on the running PHP, and GitHub Actions workflows' PHP version detection against the PHP `composer.json` requires; none of these fails a test run when stale, so nothing else catches them
- **Standalone binary**: `bin/version-pins-check <project-root> <phpunit.xml> <composerRequireChecker.json>`
- **Alias**: `vendor/bin/qa -t vp`
- **Identifier**: `phpqaci.versionPins`
- **Details**: [docs/tools/versionPins.md](docs/tools/versionPins.md)

### PHP Strict Types

- **Purpose**: Ensures all PHP files have `declare(strict_types=1)`
- **Lane**: [src/Pipeline/Lane/PhpStrictTypesTool.php](src/Pipeline/Lane/PhpStrictTypesTool.php)
- **How it works**: Scans `.php`/`.phtml` files under the checked paths for a missing declaration
- **Read-only run**: reports every offending file and fails
- **Writable run**: adds the declaration to the opening `<?php` tag automatically and reports each fixed file; a file with no opening tag fails the gate
- **Details**: [docs/tools/phpStrictTypes.md](docs/tools/phpStrictTypes.md)

### PHP Lint

- **Purpose**: Fast parallel syntax checking
- **Lane**: [src/Pipeline/Lane/PhpLintTool.php](src/Pipeline/Lane/PhpLintTool.php)
- **How it works**: Uses PHP's built-in `-l` flag to check syntax, runs in parallel for speed
- **Key features**:
  - Much faster than full parsing
  - Catches parse errors before running other tools
- **Details**: [docs/tools/phpLint.md](docs/tools/phpLint.md)

### Composer Require Checker

- **Purpose**: Ensures all code dependencies are explicitly declared in composer.json
- **Lane**: [src/Pipeline/Lane/ComposerRequireCheckerTool.php](src/Pipeline/Lane/ComposerRequireCheckerTool.php)
- **Default**: [configDefaults/generic/composerRequireChecker.json](configDefaults/generic/composerRequireChecker.json)
- **How it works**:
  - Scans all PHP files for symbols (classes, functions, constants)
  - Checks if each symbol's package is explicitly required in composer.json
  - Fails if using transitive dependencies without declaring them
- **Key principles**:
  - **Explicit is better than implicit** - If you use it, declare it
  - **Don't rely on transitive dependencies** - They might be removed
  - Example: If you use `Symfony\Component\HttpKernel\Kernel`, you must require `symfony/http-kernel` even if it's installed via `symfony/framework-bundle`
- **Common issues**:
  - Using Symfony components without explicit require
  - Safe functions from `thecodingmachine/safe` after Rector conversion
  - PSR interfaces without requiring the PSR package
- **Safe scan-files**: the `thecodingmachine/safe` entries in the resolved `composerRequireChecker.json` are checked against the running PHP by the Version Pins lane (see [docs/tools/versionPins.md](docs/tools/versionPins.md))
- **Details**: [docs/tools/composerRequireChecker.md](docs/tools/composerRequireChecker.md)

### Composer Dependency Analyser

- **Purpose**: The other direction of the dependency question — every declared package is used, every used package is declared, and neither is on the wrong side of `require` / `require-dev`
- **Lane**: [src/Pipeline/Lane/ComposerDependencyAnalyserTool.php](src/Pipeline/Lane/ComposerDependencyAnalyserTool.php)
- **Default**: [configDefaults/generic/composer-dependency-analyser.php](configDefaults/generic/composer-dependency-analyser.php)
- **Why both**: Composer Require Checker traces symbols to packages, so it can only ever find a *missing* declaration. A package nothing uses emits no symbol, so it is invisible to that lane and stays installed forever. This one reads the declarations instead
- **Reports**: unused dependency, shadow dependency, dev-dependency-in-prod, prod-dependency-only-in-dev, unknown class/function
- **Caution**: the tool exits `1` both for findings and for its own errors, so the lane cannot tell them apart — read the output for a red `Error:` line before assuming a finding
- **Alias**: `vendor/bin/qa -t cda`
- **Details**: [docs/tools/composerDependencyAnalyser.md](docs/tools/composerDependencyAnalyser.md)

### Markdown Links Checker

- **Purpose**: Validates links in markdown documentation
- **Lane**: [src/Pipeline/Lane/MarkdownLinksTool.php](src/Pipeline/Lane/MarkdownLinksTool.php) (in-process; `bin/mdlinks` remains for standalone use)
- **How it works**: Parses markdown files, checks internal file links and external URLs
- **Scope**: README.md and all files in docs/
- **Details**: [docs/tools/markdownLinks.md](docs/tools/markdownLinks.md)

### Branch Name Policy

- **Purpose**: Enforces the PR branch-naming convention (a PR branch must use an allowed prefix — `feature/`, `bugfix/`, `chore/`, `hotfix/` — never `plan/*`); the repo's detected default branch is exempt
- **Lane**: [src/Pipeline/Lane/BranchNamePolicyTool.php](src/Pipeline/Lane/BranchNamePolicyTool.php) (git probes via the process runner, YAML config via nette/neon, a pure decision class)
- **When it runs**: Always-on, first tool in Phase 3 (Static Analysis)
- **Fallback**: on default-branch detection failure it warns and requires explicit config in `qaConfig/branchNamePolicy.yaml` — there is no hardcoded default-branch guess list
- **Details**: [CLAUDE/branch-policy.md](CLAUDE/branch-policy.md)

### PHPStan

- **Purpose**: Static analysis for finding bugs without running code
- **Lane**: [src/Pipeline/Lane/PhpstanTool.php](src/Pipeline/Lane/PhpstanTool.php)
- **Default**: [configDefaults/generic/phpstan.neon](configDefaults/generic/phpstan.neon)
- **How it works**: Builds understanding of entire codebase, performs type inference and checks. The lane writes a wrapper neon that includes the resolved config and caps `parallel.maximumNumberOfProcesses` at half the CPU threads; a crash re-runs with `--debug`; `--json` puts the structured report on the real stdout
- **Key features**:
  - Configurable levels 0-9 (max)
  - Extensible with custom rules
  - Understands PHPDoc annotations
- **Details**: [docs/tools/phpstan.md](docs/tools/phpstan.md)

### PHPArkitect

- **Purpose**: Enforce architectural/structural rules — class-naming conventions, namespace layering, dependency direction — that PHPStan expresses awkwardly
- **Lane**: [src/Pipeline/Lane/PhpArkitectTool.php](src/Pipeline/Lane/PhpArkitectTool.php)
- **PHAR**: `vendor-phar/phparkitect.phar` (PHIVE, key `47CD54B6398FE21B3709D0A4D9C905CED1932CA2`, short id `D9C905CED1932CA2`)
- **Entry config (default)**: [configDefaults/generic/phparkitect.php](configDefaults/generic/phparkitect.php) — applies the default tier to the detected source dir when a project has no `qaConfig/phparkitect.php`
- **Rule tiers**: `phparkitect-rules-default.php` (on by default), `phparkitect-rules-optional.php` + `phparkitect-rules-optional-symfony.php` (opt-in) under [configDefaults/generic](configDefaults/generic)
- **Project template**: [templates/qaConfig-phparkitect.php](templates/qaConfig-phparkitect.php)
- **How it works**: parses each class into an AST and matches expressions (naming, dependencies); rules and the paths to scan are defined inside the config (so `-p` does not apply). The lane passes `--autoload` and exports the tier paths, the detected `srcDir` and the excluded paths as env vars
- **Where a rule belongs (PHPArkitect vs PHPStan)**: arkitect by default for structural rules; upgrade to a PHPStan rule only for finer-grained / method-level / semantic detection arkitect cannot express. **One owner per convention by preference**: migrate rather than duplicate where arkitect can express the rule; overlap between engines is acceptable when kept in sync and documented. Full decision guide: [README.md "Where does a rule belong"](README.md#where-does-a-rule-belong--phparkitect-or-phpstan)
- **Excluding generated code at any path**: the default config always excludes a `Generated` dir; for generated code elsewhere declare `->withArkitectExcludedPaths('Some/Path')` in `qaConfig/qa.php` (no config copy needed — matched via `Glob::toRegex` against the `src/`-relative path; exported as `PHPQACI_ARKITECT_EXCLUDE_PATHS`). Prefer this over `withArkitect(false)`, which drops rules for the whole project. See [README.md "Excluding generated code"](README.md#excluding-generated-code-at-any-path)
- **Full usage** (tiers, extend/replace/customise, disable): see the [PHPArkitect section in README.md](README.md#phparkitect-architecture-rules) and [docs/tools/phpArkitect.md](docs/tools/phpArkitect.md)

### SensitiveParameter Usage

- **Purpose**: Always-on security baseline: fails if `#[\SensitiveParameter]` is used nowhere in `src/`
- **Lane**: [src/Pipeline/Lane/SensitiveParameterUsageTool.php](src/Pipeline/Lane/SensitiveParameterUsageTool.php)
- **Opt-out**: `withSensitiveParameterCheck(false)` in `qaConfig/qa.php`
- **Details**: [docs/tools/sensitiveParameterUsage.md](docs/tools/sensitiveParameterUsage.md)

### PHPUnit

- **Purpose**: Unit testing framework
- **Lane**: [src/Pipeline/Lane/PhpunitTool.php](src/Pipeline/Lane/PhpunitTool.php) (argument assembly in [src/Pipeline/Lane/Phpunit/PhpunitArguments.php](src/Pipeline/Lane/Phpunit/PhpunitArguments.php))
- **Default**: [configDefaults/generic/phpunit.xml](configDefaults/generic/phpunit.xml)
- **How it works**: Discovers and runs test methods, reports results; a placeholder `tests/bootstrap.php` is created if missing; paratest is used when installed
- **Key features**:
  - Coverage analysis with Xdebug
  - Parallel execution support
  - Multiple output formats
- **Details**: [docs/tools/phpunit.md](docs/tools/phpunit.md)

### Infection

- **Purpose**: Mutation testing to verify test quality
- **Lane**: [src/Pipeline/Lane/InfectionTool.php](src/Pipeline/Lane/InfectionTool.php) (argument assembly and the committed-history diff filter under [src/Pipeline/Lane/Infection/](src/Pipeline/Lane/Infection/))
- **Default**: [configDefaults/generic/infection.json](configDefaults/generic/infection.json)
- **How it works**: Modifies source code (mutations), runs tests to see if they catch the changes. Reuses the coverage the phpunit lane produced in the same run, or generates it fresh for a standalone `-t infection`; always `--skip-initial-tests`; opt-in diff mode via `withInfectionDiffBase()` / `infectionDiffBase`
- **Requirements**: Xdebug and code coverage enabled
- **Key metrics**:
  - MSI (Mutation Score Indicator)
  - Covered Code MSI
- **Details**: [docs/tools/infection.md](docs/tools/infection.md)

### PHPCPD

- **Purpose**: Report duplicated code after a green run
- **Lane**: [src/Pipeline/Lane/PhpcpdTool.php](src/Pipeline/Lane/PhpcpdTool.php)
- **Output**: a JSON report under `var/qa/phpcpd/`, plus the summary on screen. Cannot fail the pipeline: phpcpd returns `1` for both "found clones" and its own errors, and duplication is a judgement call in any case
- **Alias**: `vendor/bin/qa -t cpd`
- **Details**: [docs/tools/phpcpd.md](docs/tools/phpcpd.md)

### Twig CS Fixer, Twig Lint and Yaml Lint (Symfony platform lanes)

- **Lanes**: [src/Pipeline/Lane/TwigCsFixerTool.php](src/Pipeline/Lane/TwigCsFixerTool.php), [src/Pipeline/Lane/TwigLintTool.php](src/Pipeline/Lane/TwigLintTool.php), [src/Pipeline/Lane/YamlLintTool.php](src/Pipeline/Lane/YamlLintTool.php)
- **When they run**: on a Symfony project only, skipped cleanly elsewhere. Twig CS Fixer is appended to Phase 1 (it modifies code); Twig Lint and Yaml Lint to Phase 2
- **Twig CS Fixer vs Twig Lint**: the fixer checks how templates are *written* (the shipped `TwigCsFixer` standard, `--fix` in a writable run); the linter checks they *compile*. PHP CS Fixer reads no Twig at all, which is the gap the fixer closes
- **Directories**: `templates/` and `config/` by default; `withTwigDirectories()` / `withYamlDirectories()` in `qaConfig/qa.php`
- **Details**: [docs/tools/twigCsFixer.md](docs/tools/twigCsFixer.md), [docs/tools/twigLint.md](docs/tools/twigLint.md), [docs/tools/yamlLint.md](docs/tools/yamlLint.md)

## Important Notes

1. **Tools modify code in Phase 1** - This is why Rector and PHP CS Fixer run first
2. **Project's vendor/bin is used** - Not php-qa-ci's internal vendor directory
3. **Configuration is highly flexible** - Almost every aspect can be overridden, and every override is typed PHP that fails at load time when misspelt
4. **Platform detection is automatic** - Symfony adds its lanes; there is no override switch
5. **Fail-fast design** - Pipeline stops on the first tool failure (except in interactive retry mode, and except in read-only aggregate mode, where every failing tool is collected and reported together — see "Read-Only / CI Verification Mode")

## Design Philosophy: Standardized Configuration

### The QA Pipeline is NOT a Tool Proxy

**CRITICAL UNDERSTANDING**: The PHP-QA-CI pipeline is designed to enforce consistent, standardized tool configurations across projects. It is **NOT** intended to be a flexible proxy that passes arbitrary arguments to underlying tools.

#### What the QA Pipeline IS For:

- ✅ **Enforcing consistent configurations** - Same PHPStan level, same CS Fixer rules across projects
- ✅ **Orchestrating tool execution** - Running tools in the correct order with proper dependencies
- ✅ **Managing tool dependencies** - Handling PHIVE installs, cache directories, etc.
- ✅ **Path specification** - Running tools against specific directories: `vendor/bin/qa -t stan -p src/Domain`
- ✅ **Standardized environments** - Consistent Xdebug settings, memory limits, etc.

#### What the QA Pipeline is NOT For:

- ❌ **Arbitrary tool flags** - Don't expect `vendor/bin/qa -t stan --help` to work
- ❌ **Custom tool arguments** - The pipeline controls all tool arguments for consistency
- ❌ **Tool-specific customization per run** - Use project config files instead
- ❌ **Direct tool replacement** - Not a substitute for running tools directly when needed

### Why This Design?

1. **Consistency** - Every project using the QA pipeline runs tools with the same standards
2. **Maintainability** - Tool configurations are managed centrally, not scattered across command invocations
3. **Reliability** - No chance of accidentally running with wrong flags or missing dependencies
4. **Standardization** - Teams can depend on consistent tool behavior across projects

### When You Need Flexibility

If you need to run a tool with custom arguments that the QA pipeline doesn't support:

1. **For configuration changes**: Create/modify project config files in `qaConfig/`
2. **For one-off runs**: Call the tool binary directly: `vendor/bin/phpstan analyse --help`
3. **For custom workflows**: Create your own wrapper scripts that call tools directly

### Path Specification Examples

The QA pipeline DOES support specifying which paths to scan:

```bash
# Run PHPStan only on src directory
vendor/bin/qa -t stan -p src

# Run PHP CS Fixer only on Domain namespace
vendor/bin/qa -t fixer -p src/Domain

# Run full pipeline on specific path
vendor/bin/qa -p tests/Unit
```

This maintains consistency while allowing targeted execution.

### Integration with Development Tools

Development scripts (like docker.bash) should:

- ✅ Use `vendor/bin/qa -t toolname` for standardized runs
- ✅ Support path specification: `-p src/specific/path`
- ❌ Try to pass arbitrary tool flags through the QA pipeline
- ✅ Fall back to direct tool execution when custom flags are actually needed

**Remember**: The QA pipeline's strength is its consistency, not its flexibility. Use it for what it's designed for.

<hooksdaemon>
<!-- Auto-generated by hooks daemon on restart. Do not edit this section — changes will be overwritten. -->

## Hooks Daemon — Active Handler Guidance

The handlers listed below are active in this project. Read this section to avoid triggering unnecessary blocks.

**When a tool is blocked by a handler, do not stop working.** Read the block reason, modify your approach, and continue with your task.

**A file written through Bash is not seen by the content guards that run BEFORE the write.** The PreToolUse handlers below that inspect what a file CONTAINS, or where it lives, key on the `Write` and `Edit` tools — so a `>`, `>>`, `tee` or a `cat <<EOF` heredoc reaches disk unexamined by them: no block, no advisory, no record. **A Bash write that drew no complaint is NOT a write that passed those checks** — use `Write`/`Edit` for file content and they apply.

**The LINTERS are the exception, and they DENY.** `lint_on_edit` and `validate_eslint_on_write` do run on a file a Bash command AUTHORS — a redirect, `tee`, a heredoc — so unparseable Python or failing TypeScript is reported however it reached disk. The write has already landed, so the denial is a failure report to repair with `Edit`, not a rollback. A file the command merely RELOCATES (`cp`, `mv`, `install`, `dd`) is never linted: those bytes were already on disk, so blaming the copy would report a defect the command did not introduce.

The handlers that judge a Bash COMMAND — destructive git, `sed`, pipes, permissions, `curl | sh` — are unaffected and still cover you.

Full detail on any rule: `bin/hooks-daemon explain-rule <ID>`.

## All other enforced rules

<!-- handler: require-absolute-paths -->

<!-- handler: block-ancestry-severing-merge -->

<!-- handler: block-artefact-publishing -->

<!-- handler: bash-safe-mode -->

<!-- handler: block-comment-changelog -->

<!-- handler: block-comment-size -->

<!-- handler: block-curl-pipe-shell -->

<!-- handler: daemon-location-guard -->

<!-- handler: block-dangerous-permissions -->

<!-- handler: prevent-destructive-git -->

<!-- handler: docs-qa-commit-gate -->

<!-- handler: docs-qa-edit -->

<!-- handler: error-hiding-blocker -->

<!-- handler: flaggable-content-channel-guard -->

<!-- handler: require-gh-issue-comments -->

<!-- handler: require-gh-pr-comments -->

<!-- handler: block-git-message-backtick -->

<!-- handler: block-git-stash -->

<!-- handler: github_auto_close_keywords -->

<!-- handler: lock-file-edit-blocker -->

<!-- handler: enforce-lsp-usage -->

<!-- handler: enforce-markdown-organization -->

<!-- handler: enforce-npm-commands -->

<!-- handler: block-pip-break-system -->

<!-- handler: pipe-blocker -->

<!-- handler: plan-number-helper -->

<!-- handler: plan-qa-commit-gate -->

<!-- handler: plan-qa-edit -->

<!-- handler: block-plan-time-estimates -->

<!-- handler: enforce-project-containment -->

<!-- handler: qa-suppression-blocker -->

<!-- handler: quarantine-artefact-read-guard -->

<!-- handler: remote-docs-commit-gate -->

<!-- handler: remote-docs-provenance -->

<!-- handler: remote-docs-routing -->

<!-- handler: root-recursion-guard -->

<!-- handler: block-secret-file-read -->

<!-- handler: block-security-antipatterns -->

<!-- handler: block-sed-command -->

<!-- handler: block-sensitive-content -->

<!-- handler: staged-lint-gate -->

<!-- handler: block-sudo-pip -->

<!-- handler: validate-instruction-content -->

<!-- handler: verification-result-gate -->

<!-- handler: prevent-worktree-file-copying -->

<!-- handler: block-unread-overwrite -->

<!-- handler: lint-on-edit -->

<!-- handler: failsafe-cron-blockage-suppressor -->

<!-- handler: auto-continue-stop -->

| ID                                 | Blocked                                                                                                            | Why                                                                                                                                                                                               | Fix                                                                                         |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| R-ABSOLUTE-PATH-REQUIRED           | `Read`/`Write`/`Edit` file_path requires absolute path                                                             | Ambiguous about the current working directory and can target the wrong file                                                                                                                       | Use an absolute path starting with /                                                        |
| R-GIT-MERGE-SQUASH                 | `git merge --squash`                                                                                               | Severs ancestry -- git branch -d refuses the branch forever                                                                                                                                       | Use git merge --no-ff instead                                                               |
| R-GH-PR-MERGE-SQUASH               | `gh pr merge --squash`                                                                                             | Severs ancestry -- git branch -d refuses the branch forever                                                                                                                                       | Use gh pr merge --merge instead                                                             |
| R-GH-PR-MERGE-REBASE               | `gh pr merge --rebase`                                                                                             | Severs ancestry -- git branch -d refuses the branch forever                                                                                                                                       | Use gh pr merge --merge instead                                                             |
| R-ARTIFACT-PUBLISH                 | publishing an artefact via the `Artifact` tool                                                                     | The page lives OUTSIDE the project and the repository cannot audit or retract it                                                                                                                  | Write the file locally and tell the user its path, or ask a human to publish                |
| R-BASH-SAFE-MODE-PRELUDE-MISSING   | a sequenced Bash invocation with no `set` safety prelude                                                           | Errors in earlier statements can be silently ignored                                                                                                                                              | Add `set -euo pipefail` at the top, or gate explicitly with `&&`/\`                         |
| R-COMMENT-CHANGELOG                | changelog narrative in a code comment                                                                              | A comment describes CURRENT STATE; history belongs elsewhere                                                                                                                                      | Move it to git, a changelog file, or the plan's JOURNAL/                                    |
| R-COMMENT-SIZE                     | a comment growing past its configured size limit                                                                   | Comments should describe current state, not accumulate                                                                                                                                            | Shorten the comment, or declare MUST_EXCEED_COMMENT_SIZE_BECAUSE                            |
| R-CURL-PIPE-SHELL                  | \`curl                                                                                                             | wget ...                                                                                                                                                                                          | bash                                                                                        |
| R-DAEMON-DIR-CD                    | `cd` into `.claude/hooks-daemon/`                                                                                  | Daemon CLI commands must be run from PROJECT ROOT, causing path confusion otherwise                                                                                                               | Run daemon commands from project root, e.g. `bin/hooks-daemon status`                       |
| R-CHMOD-WORLD-WRITABLE             | `chmod 777`/`chmod a+w`/`chmod o+w`                                                                                | Allows anyone to read, write, and execute, bypassing all file permission security                                                                                                                 | Use least-privilege permissions instead (755/644/600)                                       |
| R-GIT-RESET-HARD                   | `git reset --hard`                                                                                                 | Permanently destroys all uncommitted changes                                                                                                                                                      | Ask the user to run it manually                                                             |
| R-GIT-CLEAN-FORCE                  | `git clean -f`                                                                                                     | Permanently deletes untracked files                                                                                                                                                               | Ask the user to run it manually                                                             |
| R-GIT-CHECKOUT-DISCARD             | `git checkout -- <file>` / `git checkout .`                                                                        | Discards local changes to file(s) permanently                                                                                                                                                     | Ask the user to run it manually                                                             |
| R-GIT-RESTORE                      | `git restore <file>`                                                                                               | Discards local changes to files permanently (`--staged`/`-S` is allowed)                                                                                                                          | Ask the user to run it manually                                                             |
| R-GIT-STASH-DROP                   | `git stash drop`                                                                                                   | Permanently destroys a stashed change                                                                                                                                                             | Ask the user to run it manually                                                             |
| R-GIT-STASH-CLEAR                  | `git stash clear`                                                                                                  | Permanently destroys all stashed changes                                                                                                                                                          | Ask the user to run it manually                                                             |
| R-GIT-PUSH-FORCE                   | `git push --force`                                                                                                 | Can overwrite remote history and destroy team members' work                                                                                                                                       | Ask the user to run it manually, or coordinate and use `--force-with-lease`                 |
| R-GIT-BRANCH-FORCE-DELETE          | `git branch -D`                                                                                                    | Force-deletes a branch without checking if it has been merged                                                                                                                                     | Use `git branch -d` first (refuses unmerged branches); ask the user for -D                  |
| R-GIT-COMMIT-AMEND                 | `git commit --amend`                                                                                               | Rewrites the previous commit, creating messy history and potential data loss                                                                                                                      | Create a new commit instead                                                                 |
| R-DOCS-QA-COMMIT                   | a git commit violates a block-level docs QA staged-tree check                                                      | Most doc rot that matters at commit time is cross-file drift a single-file edit hook cannot see                                                                                                   | Fix the content per each finding's remediation below and amend the commit                   |
| R-DOCS-QA-EDIT                     | a documentation Write/Edit violates a block-level docs QA check                                                    | A finding only denies the write when it is BLOCK severity AND the resolved mode for that check is block                                                                                           | Fix the content per each finding's remediation below and retry                              |
| R-ERROR-HIDING                     | an error-hiding pattern (bare except,                                                                              |                                                                                                                                                                                                   | true, empty catch, result, _ := ..., ...)                                                   |
| R-FLAGGABLE-CONTENT-CHANNEL        | a content-revealing git/grep command shape over a flaggable path                                                   | It would reveal flaggable content inside routine command output, with no deliberate Read at all                                                                                                   | Delegate the WHOLE review to the quarantine subagent instead                                |
| R-GH-ISSUE-VIEW-NO-COMMENTS        | `gh issue view` without `--comments`                                                                               | Issue comments contain critical context, clarifications and updates not in the issue body                                                                                                         | Add --comments, or include comments in --json fields                                        |
| R-GH-PR-VIEW-NO-COMMENTS           | `gh pr view` without `--comments`                                                                                  | PR comments contain review feedback and discussion context not in the PR body                                                                                                                     | Add --comments, or include comments in --json fields                                        |
| R-GIT-MESSAGE-BACKTICK             | an unescaped backtick in a double-quoted git commit/tag message                                                    | Bash performs command substitution inside double quotes -- the span is EXECUTED, not quoted                                                                                                       | Use single quotes, or git commit -F <file>                                                  |
| R-GIT-STASH-PUSH                   | `git stash` / `git stash push` / `git stash save`                                                                  | Stashes get forgotten, lost, and block git pull                                                                                                                                                   | Use git commit instead — WIP commits are fine                                               |
| R-GH-AUTO-CLOSE-KEYWORD            | a GitHub closing keyword + issue reference in a git/gh message                                                     | Auto-closes the referenced issue/PR the moment the commit reaches the default branch, and cannot be disabled repository-side                                                                      | Use a non-closing reference instead, e.g. Addresses #123                                    |
| R-LOCK-FILE-EDIT                   | Direct `Write`/`Edit` of a package manager lock file                                                               | Lock files are generated artifacts; manual edits create checksum mismatches and broken dependency graphs                                                                                          | Use the package manager commands instead (e.g. `npm install`, `cargo update`)               |
| R-LSP-SYMBOL-LOOKUP                | a symbol-like Grep/Bash grep lookup                                                                                | LSP tools give semantic ~50ms code intelligence; grep is slow and imprecise                                                                                                                       | Use goToDefinition/findReferences/workspaceSymbol/hover/documentSymbol instead              |
| R-MARKDOWN-WRONG-LOCATION          | MARKDOWN FILE IN WRONG LOCATION — a new `.md` file written to an unrecognised location                             | Markdown files must follow project organization rules                                                                                                                                             | Move it into an allowed location, or configure `extra_allowed_markdown_paths`               |
| R-MARKDOWN-UNTRACKED-MEMORY        | UNTRACKED CLAUDE MEMORY IS DISABLED FOR THIS PROJECT — a write to `~/.claude/projects/*/memory/*.md`               | That knowledge is per-checkout, un-reviewed, and invisible to teammates — it drifts from the repo and bypasses code review                                                                        | Document it in tracked project docs instead (CLAUDE.md, .claude/rules/\*.md, docs/)         |
| R-MARKDOWN-PLAN-SYNC               | a `.claude/settings.json` `plansDirectory` out of sync with the daemon's plan_workflow config                      | Plan workflow requires plansDirectory to match daemon config to redirect writes correctly                                                                                                         | Fix `.claude/settings.json`'s `plansDirectory` key, then restart your session               |
| R-NPM-PIPED-COMMAND                | a piped `npm run`/`npx` command                                                                                    | Piping npm/npx commands is pointless — llm: cache files hold the full data                                                                                                                        | Run the plain command, then query the cache file with jq                                    |
| R-NPM-NON-LLM-COMMAND              | a raw `npm run`/`npx` command when llm: wrappers exist                                                             | llm: commands provide LLM-friendly, machine-readable output                                                                                                                                       | Use the project's `npm run llm:*` equivalent instead                                        |
| R-PIP-BREAK-SYSTEM-PACKAGES        | `pip install --break-system-packages`                                                                              | Bypasses PEP 668 protection and can corrupt the system Python installation                                                                                                                        | Use a virtual environment or `pip install --user` instead                                   |
| R-PIPE-TO-TAIL                     | \`                                                                                                                 | tail\`                                                                                                                                                                                            | Truncates output and causes information loss                                                |
| R-PIPE-TO-HEAD                     | \`                                                                                                                 | head\`                                                                                                                                                                                            | Truncates output and causes information loss                                                |
| R-PLAN-NUMBER-DISCOVERY            | a bash discovery scan (ls/find/sort+tail) for the next plan number                                                 | Misses subdirectories like Completed/ and disagrees across branches                                                                                                                               | Use the printed next plan number, or the git counter directly                               |
| R-PLAN-FOLDER-MKDIR                | `mkdir <plan-dir>/NNNNN-name` (hand-creating a plan folder)                                                        | Claims a plan number the moment the folder appears, but nothing records the claim until PLAN.md is written                                                                                        | Use the mkplan.bash scaffolder instead                                                      |
| R-PLAN-QA-COMMIT                   | a git commit violates a block-level plan QA cross-file invariant                                                   | Most plan rot is cross-file and a single-file edit hook cannot see it                                                                                                                             | Amend the commit to also stage what each finding's remediation names below                  |
| R-PLAN-QA-EDIT                     | a PLAN.md/README.md Write/Edit violates a block-level plan QA check                                                | Plan QA linting catches issues you can fix immediately, before they reach commit                                                                                                                  | Fix the content per each finding's remediation below and retry                              |
| R-PLAN-TIME-ESTIMATE               | Time estimates not allowed in plan documents                                                                       | Time estimates in plans create false expectations and pressure                                                                                                                                    | Break work into concrete tasks and implementation steps; let the user decide scheduling     |
| R-WRITE-OUTSIDE-PROJECT-ROOT       | a write whose target is outside the repository root                                                                | Outside the repo nothing is version-controlled, reviewed or durable — a container's temp directory is wiped on restart, and every other path rule is scoped to the repo so none of them judges it | Write it inside the repository — `untracked/scratch/` is the scratch location               |
| R-QA-SUPPRESSION                   | a QA suppression directive (noqa, type: ignore, eslint-disable, ...)                                               | Suppression comments hide real problems and create technical debt                                                                                                                                 | Fix the underlying issue; do not suppress the warning                                       |
| R-QUARANTINE-ARTEFACT-READ         | reading a quarantined `*-opus-security-DETAIL*` artefact into the coordinator                                      | A DETAIL artefact holds raw flaggable substance meant for a human or another quarantine agent only                                                                                                | Read the paired `*-opus-security-SUMMARY*` artefact instead                                 |
| R-REMOTE-DOCS-STAGED-PROVENANCE    | a commit staging a remote-docs file without valid provenance frontmatter                                           | An unattributed vendored document that reaches history needs a rewrite to remove, and cannot be refreshed, dated or trusted meanwhile                                                             | Capture with `hooks-daemon remote-docs add <url>` and re-stage                              |
| R-REMOTE-DOCS-PROVENANCE           | a write into the remote-docs tree without valid provenance frontmatter                                             | A vendored document with no recorded source is indistinguishable from something we wrote ourselves, and cannot be refreshed, dated or trusted                                                     | Capture with `hooks-daemon remote-docs add <url>` instead of hand-authoring                 |
| R-REMOTE-DOCS-VENDORED-COPY        | a WebFetch of a URL this project already holds a fresh vendored copy of                                            | The local copy is faster, costs no network round trip, and is the corpus the remote-docs tree exists to build                                                                                     | Read the local path named in the message, or refresh it if you need newer content           |
| R-ROOT-RECURSION-CATASTROPHIC      | `grep -r`/`find`/`rg`/... rooted at `/`, `/proc`, `/sys`, `/home`, `/root`, `~`, `$HOME`                           | Walks the entire filesystem and can pin every CPU core for hours                                                                                                                                  | Scope the search to the project (e.g. `rg -l "pattern" .`)                                  |
| R-SECRET-READ                      | Read/Write/Edit/NotebookEdit/Grep targeting a protected path                                                       | The file's contents must NEVER be read into context by any route — not Read, not Bash, not an interpreter one-liner, not a copy                                                                   | Use `bin/hooks-daemon secret-meta <path>` for metadata, or ask the user                     |
| R-SECRET-BASH-MENTION              | a Bash command whose text mentions a protected path                                                                | The file's contents must NEVER be read into context by any route — not Read, not Bash, not an interpreter one-liner, not a copy                                                                   | Use `bin/hooks-daemon secret-meta <path>` for metadata, or ask the user                     |
| R-SECRET-SCRIPT-AUTHOR             | a script authored via Write/Edit whose content references a protected path                                         | The file's contents must NEVER be read into context by any route — not Read, not Bash, not an interpreter one-liner, not a copy                                                                   | Use `bin/hooks-daemon secret-meta <path>` for metadata, or ask the user                     |
| R-SEC-CODE-INJECTION               | `eval`, `exec`, `new Function`, `__import__`, `instance_eval`, `yaml.load`                                         | Dynamic execution of a string as code                                                                                                                                                             | Avoid dynamic code execution; use safe parsing/import alternatives                          |
| R-SEC-CMD-INJECTION                | `os.system`, `subprocess(..., shell=True)`, `shell_exec`, `proc_open`, `Runtime.exec`, `Process.Start`, `IO.popen` | Shell command construction from untrusted input enables command injection                                                                                                                         | Use argument-list APIs (no shell=True) instead of shell string concatenation                |
| R-SEC-DESERIALISATION              | `pickle.load`, `Marshal.load`, `unserialize`, `ObjectInputStream`, `XMLDecoder`, `BinaryFormatter`                 | Deserialising untrusted data can execute arbitrary code                                                                                                                                           | Use a safe serialisation format (e.g. JSON) instead                                         |
| R-SEC-XSS                          | `innerHTML`, `dangerouslySetInnerHTML`, `document.write`, `template.HTML`/`JS`/`URL`                               | Injects unescaped content into the DOM/output, enabling XSS                                                                                                                                       | Use the framework's safe templating/escaping APIs                                           |
| R-SEC-HARDCODED-CREDS              | AWS access keys, GitHub tokens, Stripe keys, private key blocks                                                    | Hardcoded credentials leak via source control history and code review                                                                                                                             | Use environment variables, never hardcode credentials                                       |
| R-SEC-UNSAFE-MEMORY                | Rust `from_raw_parts`, `transmute`                                                                                 | Bypasses Rust's memory/type safety guarantees                                                                                                                                                     | Use safe conversions (`as`, `From`/`Into`) or validated slice operations                    |
| R-SED-FILE-MODIFICATION            | `sed`                                                                                                              | Claude gets sed syntax wrong regularly and a single error can destroy hundreds of files                                                                                                           | Use the Edit tool (or parallel Haiku agents with Edit for bulk changes)                     |
| R-SENSITIVE-PUBLIC-PATTERN         | content matching a configured public pattern                                                                       | The pattern is a named, safe-to-disclose signal (a path, a placeholder, profanity, ...)                                                                                                           | Remove or replace the matched text before retrying                                          |
| R-SENSITIVE-SECRET-TERM            | content matching a configured blocked term                                                                         | A gitignored secret word list term was found in this write                                                                                                                                        | Ask the user what the cited entry covers, then remove the matching text                     |
| R-STAGED-LINT-FAILURE              | a staged file fails the cheap syntax check at commit time                                                          | lint_on_edit only ever runs at Write/Edit time, so a git add of pre-existing content skips it entirely                                                                                            | Fix the failing file(s) above and re-stage before committing                                |
| R-SUDO-PIP-INSTALL                 | `sudo pip install`                                                                                                 | Conflicts with the OS package manager and can corrupt system Python                                                                                                                               | Use a virtual environment or `pip install --user` instead                                   |
| R-INSTRUCTION-IMPLEMENTATION-LOG   | implementation logs (e.g. 'created the file X', 'added the class Y')                                               | Instruction files hold permanent instructions, not a log of past edits                                                                                                                            | Remove the log sentence; put implementation history in git or a plan JOURNAL/               |
| R-INSTRUCTION-STATUS-INDICATOR     | status indicators (e.g. checkmark + 'Complete', 'Done', 'Success', 'Fixed')                                        | A completion emoji records a moment in time, not a permanent fact                                                                                                                                 | Remove the status marker; instruction files describe the project, not its history           |
| R-INSTRUCTION-TIMESTAMP            | timestamps (ISO dates such as 2024-03-15)                                                                          | A dated entry is a log line, and instruction files are not a log                                                                                                                                  | Remove the date; if it is genuinely load-bearing, put it in git history                     |
| R-INSTRUCTION-LLM-SUMMARY          | LLM summaries (section headings such as '## Summary', '## Key Points', '## Overview')                              | A summary heading is the shape an LLM's own turn-report takes, not project documentation                                                                                                          | Remove the heading and fold any durable content into the surrounding instructions           |
| R-INSTRUCTION-TEST-OUTPUT          | test output counts (e.g. '42 tests passed', '1 test failed')                                                       | A test run's result is a point-in-time fact, not a stable instruction                                                                                                                             | Remove the count; CI already reports this on every run                                      |
| R-INSTRUCTION-FILE-LISTING         | changelog-style file listings (e.g. 'created src/Service/Foo.php')                                                 | A file path preceded by a past-tense action verb is changelog narrative                                                                                                                           | Remove the log line; a bare path reference used as documentation stays allowed              |
| R-INSTRUCTION-CHANGE-SUMMARY       | change summaries (e.g. 'Added 15 lines', 'Removed 8 lines')                                                        | A line-count delta describes one diff, not a stable instruction                                                                                                                                   | Remove the summary; the diff itself is preserved in git                                     |
| R-INSTRUCTION-COMPLETION-INDICATOR | completion indicators (e.g. 'ALL DONE!', 'Task complete!', 'Finished task')                                        | A completion phrase announces a session's end, not a fact about the project                                                                                                                       | Remove the phrase; instruction files should never celebrate finishing a task                |
| R-VERIFICATION-RESULT-NOT-CONSUMED | a verifier followed by a mutator with nothing consuming the result                                                 | The verifier can fail and the mutator would still run                                                                                                                                             | Gate with `&&`, an explicit exit-code check, or `set -euo pipefail`                         |
| R-WORKTREE-FILE-COPY               | `cp`/`mv`/`rsync` between a worktree and the main repo                                                             | Defeats worktree isolation, bypasses git tracking, and can nuke untracked work in the target directory                                                                                            | cd into the worktree, commit, then git merge back                                           |
| R-WRITE-CLOBBER                    | `Write` to an existing file you have not read this session                                                         | You cannot know what you are destroying, so you could not report the loss even afterwards                                                                                                         | `Read` the file then retry, or use `Edit` for a targeted change                             |
| R-LINT-FAILURE                     | a written/authored file that fails its language's lint check                                                       | The write has already landed on disk; this is a failure report, not a rollback                                                                                                                    | Fix the reported problems with Edit — do not re-Write the file from scratch                 |
| R-FAILSAFE-CRON-SUPPRESSED         | A delivered failsafe-cron tick, while a 'blocked only on human input' marker is live                               | Every tick against a session blocked only on human input is a guaranteed no-op model turn                                                                                                         | Nothing to do -- this is expected. Send a real message to clear the marker and resume ticks |
| R-STOP-QA-FAILURE                  | Stopping while the last QA tool run's own output indicated failure                                                 | QA failures detected in the last QA tool run                                                                                                                                                      | Fix the failures, re-run the QA tool, and continue without stopping                         |
| R-STOP-TAUTOLOGICAL-QUESTION       | Stopping behind a rhetorical continue/confirmation question                                                        | The answer is obvious -- yes, continue the already-planned work now                                                                                                                               | Resume the next unit of work immediately; STOPPING BECAUSE: does not exempt this            |
| R-STOP-AFTER-TOOL-ERROR            | Stopping right after an unresolved tool_use_error                                                                  | The correct action is to address the cause and retry, not stop                                                                                                                                    | Address the tool_use_error's cause (e.g. Read before Edit/Write) and retry                  |
| R-STOP-CONFIRMATION-QUESTION       | Stopping to ask an obvious confirmation question                                                                   | The daemon auto-continues through confirmation-style questions                                                                                                                                    | Proceed with the remaining work; stop with STOPPING BECAUSE: only if truly stuck            |
| R-STOP-NO-REASON                   | Stopping without a STOPPING BECAUSE: explanation                                                                   | The stop hook enforces intentional stops                                                                                                                                                          | Prefix your stop message with STOPPING BECAUSE: <reason>, or keep working                   |
| R-STOP-GOAL-LEDGER                 | Stopping while ledgered plan(s) are still In Progress                                                              | The daemon-side goal ledger owes a goal for EVERY In Progress plan, not only the newest /goal condition                                                                                           | Continue the listed plan(s), or stop with STOPPING BECAUSE: naming why each cannot proceed  |

## Advisories and other active handlers

One line each; these fire with their own guidance when relevant. Full text: `bin/hooks-daemon explain-handler <name>`.

<!-- handler: agent-isolation-advisor -->

- agent_isolation_advisor — isolate concurrent agents

<!-- handler: dispatch-declaration -->

- dispatch_declaration — declare where a subagent's reports go

<!-- handler: flaggable-work-advisor -->

- flaggable_work_advisor — delegate flaggable work BEFORE reading it

<!-- handler: plan-workflow-guidance -->

- plan_workflow — PLAN.md, supporting docs and JOURNAL/ obey DIFFERENT contracts

<!-- handler: background-process-tracker -->

- background_process_tracker — backgrounded processes are tracked

<!-- handler: budget-exhaustion-detector -->

- budget_exhaustion_detector — hidden agent budgets are surfaced

<!-- handler: command-hints -->

- command_hints — advisory reminders after specific commands

<!-- handler: git-hooks-executable-fixer -->

- git_hooks_executable_fixer — auto-fixes non-executable git hooks

<!-- handler: goal-injection -->

- goal_injection — plan-start goal signal for the ccy supervisor

<!-- handler: recovery-cron-advisor -->

- recovery_cron_advisor — failsafe recovery cron lifecycle advisory

<!-- handler: ccy-supervisor-integrity -->

- ccy_supervisor_integrity — keep the ccy supervisor properly set up

<!-- handler: docs-qa-sweep -->

- docs_qa_sweep — documentation drift report at session start

<!-- handler: git-upstream-checker -->

- git_upstream_checker — additive fetch + pull/cleanup advice on session start

<!-- handler: hook-registration-checker -->

- hook_registration_checker — hooks configuration policy

<!-- handler: model-fallback-detector -->

- model_fallback_detector — silent model substitution is surfaced

<!-- handler: plan-qa-sweep -->

- plan_qa_sweep — plan-tree drift report at session start

<!-- handler: plan-workflow-asset-checker -->

- plan_workflow_asset_checker — plan tooling provisioning alert

<!-- handler: project-handler-load-checker -->

- project_handler_load_checker — project protection degraded alert

<!-- handler: secret-file-hygiene-checker -->

- secret_file_hygiene_checker -- on-disk hygiene for protected paths

<!-- handler: tool-disable-advisor -->

- tool_disable_advisor — declared never-want tools are checked at session start

<!-- handler: standing-authorisations -->

- standing_authorisations — a project can record a standing request

<!-- handler: auto-approve-reads -->

- auto_approve_reads — gated on bypassPermissions mode

<!-- handler: subagent-report-size-blocker -->

- subagent_report_size_blocker — write large reports to a file

<!-- handler: worktree-create -->

- worktree_create — semantic worktree naming

<!-- handler: nitpick-dismissive-language -->

- nitpick.dismissive_language — do not deflect or prematurely halt

<!-- handler: nitpick-hedging-language -->

- nitpick.hedging_language — the guessing is the defect, not the wording

</hooksdaemon>
