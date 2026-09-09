# Upgrading a project to php-qa-ci 8.5

This page is the complete migration for a consuming project moving to the `php8.5` branch.
It is written to be executed by an agent: follow the protocol in order, run every command
shown, and treat the "done when" lines as the acceptance criteria. Nothing else is needed.

The `php8.5` branch is a major cut-over: the pipeline that runs your QA is PHP, not Bash, and
the project-side configuration under `qaConfig/` is PHP too. Every file that used to be Bash
sourced into the pipeline is now **refused with an error naming this page**; it is never
silently ignored, so a half-migrated project cannot run at all.

## 0. Protocol

1. Read this page to the end before editing anything.
2. Run the inventory in section 2. It lists every file and variable you must migrate.
3. Do section 3 (dependency), then 4 (`qa.php`), 5 (tool overrides), 6 (hooks), in that order.
   Each section has a "done when" line.
4. Run the verification in section 7 until it is green.
5. Commit as described in section 8: the new PHP files and the deletion of the Bash files in
   one commit.

What does **not** change, so do not touch it:

- The command `vendor/bin/qa` and its options `-t <tool|alias>`, `-p <path>` (or a bare path),
  `--json`, `-h`; every tool alias; the phase order; the exit codes.
- Every environment variable (`CI`, `QA_READONLY`, `QA_FAIL_FAST`, `phpqaMemoryLimit`,
  `phpqaQuickTests`, `phpUnitCoverage`, `useInfection`, `mutationScoreIndicator`,
  `coveredCodeMSI`, `infectionDiffBase`, `useArkitect`, `useSensitiveParameterCheck`,
  `PHP_QA_CI_PHP_EXECUTABLE`, ...). CI scripts, Docker wrappers and `ci.bash` keep working.
- Every tool config file you copied into `qaConfig/`: `phpstan.neon`, `phpunit.xml`,
  `php_cs.php`, `php_cs_finder.php`, `infection.json`, `rector*.php`, `phparkitect*.php`,
  `composerRequireChecker.json`, `psr4-validate-ignore-list.txt`, `branchNamePolicy.yaml`.
  The three-level lookup (project `qaConfig/`, platform default, generic default) is unchanged.
- `qaConfig/PHPStan/Rules/` and the `QaConfig\` autoload-dev entry.
- The standalone binaries (`vendor/bin/psr4-validate`, `vendor/bin/mdlinks`,
  `vendor/bin/rule-doc`, `vendor/bin/rules`, ...).
- The run lock location (`qaConfig/.qa-lock/`) and the managed `.gitignore` block.

## 1. What is being replaced

| Bash-era file (refused)          | PHP replacement                                              | Section |
| -------------------------------- | ------------------------------------------------------------ | ------- |
| `qaConfig/qaConfig.inc.bash`     | `qaConfig/qa.php` returning a closure over `QaConfigBuilder` | 4       |
| `qaConfig/tools/<tool>.inc.bash` | `qaConfig/tools/<tool>.php` returning a `ToolInterface`      | 5       |
| `qaConfig/hookPre.bash`          | `qaConfig/hookPre.php` returning a callable                  | 6       |
| `qaConfig/hookPost.bash`         | `qaConfig/hookPost.php` returning a callable                 | 6       |

Dropped with no replacement:

- The timing/ETA display (`includes/generic/timing.inc.bash`). The lock keeps a last-activity
  timestamp for stale detection only.
- Overriding a tool's **config path** by variable (`phpstanConfigPath=...`,
  `phpUnitConfigPath=...`, `infectionConfig=...`, `composerRequireCheckerConfig=...`,
  `phpArkitectConfigPath=...`, `phpCsConfigPath=...`). Put the file at the conventional name
  under `qaConfig/` instead; the lookup finds it there.
- Anything a Bash file did as a side effect at source time (`echo`, `cd`, running commands,
  exporting unrelated variables). Move commands to a hook (section 6) and printing to
  `$context->writeln()`; drop the rest.

## 2. Inventory the project

Run these from the project root. Every hit is something to migrate.

```bash
# Bash-era files the pipeline will refuse
find qaConfig -maxdepth 2 \( -name '*.bash' -o -name '*.sh' \) -type f

# Every setting the Bash config set (the left-hand side of each assignment)
grep -hoE '^\s*(export\s+)?[A-Za-z_][A-Za-z0-9_]*(\+?=)' qaConfig/qaConfig.inc.bash 2>/dev/null | sort -u

# Anything else that sourced or referenced the Bash config or the includes tree
grep -rn 'qaConfig.inc.bash\|includes/generic\|\.inc\.bash\|hookPre\.bash\|hookPost\.bash' \
  --exclude-dir=vendor --exclude-dir=var --exclude-dir=node_modules .
```

Done when: you have the list of files from the first command and, for each assignment from
the second, a row in the table in section 4.2 or a decision that it belongs in a hook.

## 3. Dependency and PHP version

```json
"require": { "php": "^8.5" }
```

```bash
composer require --dev lts/php-qa-ci:dev-php8.5@dev
```

The `ergebnis/composer-normalize` plugin must be allowed in the project's `composer.json`
(`config.allow-plugins`), as before. If Composer's `config.bin-dir` is set, `vendor/bin/qa` is
wherever that points; the pipeline reads `bin-dir` itself.

Done when: `vendor/bin/qa -h` prints the usage (it exits 1 by design).

## 4. `qaConfig/qaConfig.inc.bash` becomes `qaConfig/qa.php`

### 4.1 The contract

The file returns a closure that receives the pipeline's `QaConfigBuilder` and returns the
adjusted builder. The builder is immutable: every `with*()` returns a new instance, so the
closure must return the result of the chain, not the original argument.

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withMemoryLimit('8G')
    ->withInfectionFloors(msi: 82, coveredMsi: 84)
    ->withIgnoredPaths('tests/assets', 'src/Generated')
    ->withArkitectExcludedPaths('Quote/API')
    ->withSensitiveParameterCheck(false);
```

Rules the loader enforces:

- The file must `return` a callable; the callable must return a `QaConfigBuilder`. Anything
  else aborts the run with a message naming the file and the type it got.
- A misspelt method is a PHP fatal error at load time. There is no "unknown setting is ignored"
  path any more; that was the point.
- The file is optional. A project with no adjustments needs no `qa.php`.
- A template ships at `vendor/lts/php-qa-ci/templates/qaConfig-qa.php`.

Ordering and precedence:

1. The pipeline seeds the builder from its defaults and the environment variables.
2. `qa.php` receives that builder. Whatever it sets **wins over the environment**.
3. `build()` then applies the derivations: coverage is off without Xdebug, Infection is off
   without coverage. A project cannot switch on what the host cannot run.

Consequence: an environment variable is now a per-run override for a setting `qa.php` does not
set. If `qa.php` calls `withInfection(false)`, `useInfection=1 vendor/bin/qa` does not turn it
back on. Keep permanent policy in `qa.php` and leave ad-hoc switches to the environment.

### 4.2 Every Bash variable and its replacement

| Bash variable in `qaConfig.inc.bash` | `qa.php` method                                                                                           | Notes                                                                                                                                                                                                          |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `phpqaMemoryLimit=8G`                | `withMemoryLimit('8G')`                                                                                   | Applied to every PHP process the pipeline starts, including coverage runs.                                                                                                                                     |
| `pathsToIgnore+=( "x" )`             | `withIgnoredPaths('x', ...)`                                                                              | Project-relative. Appends to the current list.                                                                                                                                                                 |
| `pathsToIgnore=()` (reset)           | none                                                                                                      | The default list is empty; the Bash placeholder token is gone.                                                                                                                                                 |
| `pathsToCheck+=( "x" )`              | `withCheckedPaths('x', ...)`                                                                              | Project-relative. Appends to the default `tests/` + `src/`. `-p` on the command line replaces the whole set for one run.                                                                                       |
| `phpUnitCoverage=0`                  | `withPhpUnitCoverage(false)`                                                                              |                                                                                                                                                                                                                |
| `phpUnitIterativeMode=1`             | `withPhpUnitIterativeMode(true)`                                                                          | The `uniterate` pseudo-tool sets this for one run.                                                                                                                                                             |
| `useInfection=0`                     | `withInfection(false)`                                                                                    |                                                                                                                                                                                                                |
| `mutationScoreIndicator=82`          | `withInfectionFloors(msi: 82, coveredMsi: ...)`                                                           | Both floors are set together.                                                                                                                                                                                  |
| `coveredCodeMSI=84`                  | `withInfectionFloors(msi: ..., coveredMsi: 84)`                                                           |                                                                                                                                                                                                                |
| `infectionMutationScoreIndicator=82` | `withInfectionFloors(msi: 82, coveredMsi: ...)`                                                           | The Bash-era **derived** name. It has no environment equivalent; it must become the method call.                                                                                                               |
| `infectionCoveredCodeMSI=84`         | `withInfectionFloors(msi: ..., coveredMsi: 84)`                                                           | Same.                                                                                                                                                                                                          |
| `infectionThreads=4`                 | `withInfectionThreads(4)`                                                                                 | Default is half the CPU threads.                                                                                                                                                                               |
| `infectionDiffBase=origin/main`      | `withInfectionDiffBase('origin/main')`                                                                    |                                                                                                                                                                                                                |
| `infectionDiffCoveredMsi=95`         | `withInfectionDiffBase('origin/main', coveredMsi: 95)`                                                    | Set with the base.                                                                                                                                                                                             |
| `useArkitect=0`                      | `withArkitect(false)`                                                                                     |                                                                                                                                                                                                                |
| `arkitectExcludePaths+=("x")`        | `withArkitectExcludedPaths('x', ...)`                                                                     | Relative to `src/`. Appends.                                                                                                                                                                                   |
| `useSensitiveParameterCheck=0`       | `withSensitiveParameterCheck(false)`                                                                      |                                                                                                                                                                                                                |
| `twigDirectories` (Symfony)          | `withTwigDirectories('templates', ...)`                                                                   | Absolute or project-relative. Replaces the default `templates/`.                                                                                                                                               |
| `yamlDirectories` (Symfony)          | `withYamlDirectories('config', ...)`                                                                      | Replaces the default `config/`.                                                                                                                                                                                |
| *(new in 8.5)*                       | `withTypeCoverageFloors(returnType: 50, paramType: 40, propertyType: 60, constantType: 80, declare: 100)` | Minimum percentage of declarations carrying a native type, per kind. Every argument is optional and every floor is off unless given. Ignored in a `-p` run, deliberately — see [phpstan.md](tools/phpstan.md). |
| *(new in 8.5)*                       | `withComposerAudit(false)`                                                                                | Turns off `composer audit` in the composerChecks lane, which an offline build needs. `useComposerAudit=0` for one run.                                                                                         |

Variables that stay **environment-only** (no builder method; set them in CI or on the command
line, never in `qa.php`):

| Variable                   | Meaning                                                                        |
| -------------------------- | ------------------------------------------------------------------------------ |
| `CI`                       | Non-interactive: no retry prompt. Auto-on under Claude Code and without a TTY. |
| `QA_READONLY`              | Fixers dry-run and a pending change fails. Auto-on under GitHub Actions.       |
| `QA_FAIL_FAST`             | Turn aggregate mode off in a read-only run.                                    |
| `phpqaQuickTests`          | Skip PHPStan, PHPUnit and Infection.                                           |
| `phpUnitQuickTests`        | Passed through to the test suite as before.                                    |
| `infectionOnlyCovered`     | Infection `--only-covered`.                                                    |
| `PHP_QA_CI_PHP_EXECUTABLE` | The PHP binary for every tool.                                                 |

Bash variables with **no replacement** (delete the line):

| Variable                                                                                                                                                  | Why                                                                                                 |
| --------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `phpstanConfigPath`, `phpUnitConfigPath`, `phpCsConfigPath`, `infectionConfig`, `composerRequireCheckerConfig`, `phpArkitectConfigPath`, `psr4IgnoreList` | Put the file under `qaConfig/` at its conventional name; the lookup resolves it.                    |
| `varDir`, `cacheDir`, `pharDir`, `binDir`, `projectRoot`, `srcDir`, `testsDir`, `projectConfigPath`                                                       | Resolved by the pipeline; read them from `$context->config->paths` in an override or hook.          |
| `qaHalfCpuThreads`, `phpVersion`, `noXdebugConfigPath`, `phpCsCacheFile`                                                                                  | Internal; the pipeline computes them.                                                               |
| `standardIFS`, `specifiedPath`, `singleToolToRun`, `useJsonOutput`                                                                                        | Internal CLI state; available as `$context->config->specifiedPath`, `->singleTool`, `->jsonOutput`. |

### 4.3 Worked example

Before (`qaConfig/qaConfig.inc.bash`):

```bash
echo "Setting Infection Minimums"
infectionMutationScoreIndicator=82
infectionCoveredCodeMSI=82
pathsToIgnore=()
pathsToIgnore+=( "tests/assets" )
pathsToIgnore+=( "src/Generated" )
export useSensitiveParameterCheck=0
export phpqaMemoryLimit=6G
composer dump-autoload
```

After (`qaConfig/qa.php`):

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withInfectionFloors(msi: 82, coveredMsi: 82)
    ->withIgnoredPaths('tests/assets', 'src/Generated')
    ->withSensitiveParameterCheck(false)
    ->withMemoryLimit('6G');
```

The `echo` is dropped (the pipeline prints "Project config applied" itself). The
`composer dump-autoload` is a command, so it moves to `qaConfig/hookPre.php` (section 6), or is
dropped because the `composerChecks` lane already runs it.

Done when: `qa.php` exists (or is deliberately absent), `qaConfig.inc.bash` is deleted, and
`vendor/bin/qa -t pt` prints "Project config applied" (when the file exists) and passes.

## 5. `qaConfig/tools/<tool>.inc.bash` becomes `qaConfig/tools/<tool>.php`

### 5.1 The contract

A Bash override was arbitrary shell sourced in place of a lane. A PHP override is a file that
returns an object implementing `LTS\PHPQA\Pipeline\Tool\ToolInterface`. It replaces the shipped
lane for that tool in every run, including `-t <tool>`.

The file name is the tool's **canonical registry name**, not an alias:

| File                                          | Replaces                                      | Aliases (for `-t`, not for the file name) |
| --------------------------------------------- | --------------------------------------------- | ----------------------------------------- |
| `tools/rector.php`                            | Rector                                        | `r`, `rector`                             |
| `tools/phpCsFixer.php`                        | PHP CS Fixer                                  | `f`, `fixer`, `csfixer`                   |
| `tools/psr4Validate.php`                      | PSR-4 validation                              | `psr`, `psr4`                             |
| `tools/composerChecks.php`                    | composer diagnose / normalize / dump-autoload | `com`, `composer`                         |
| `tools/packageType.php`                       | package type declaration                      | `pt`                                      |
| `tools/configTemplateIgnoreList.php`          | template ignore-list audit                    | `cti`                                     |
| `tools/infectionConfigSourceDirs.php`         | infection.json source dirs                    | `icsd`                                    |
| `tools/versionPins.php`                       | version pins                                  | `vp`                                      |
| `tools/phpStrictTypes.php`                    | strict types                                  | `st`, `stricttypes`                       |
| `tools/phpLint.php`                           | parallel lint                                 | `lint`, `phplint`                         |
| `tools/composerRequireChecker.php`            | composer-require-checker                      | `cr`                                      |
| `tools/composerDependencyAnalyser.php`        | composer-dependency-analyser                  | `cda`                                     |
| `tools/phpcpd.php`                            | PHPCPD copy/paste detection                   | `cpd`, `phpcpd`                           |
| `tools/markdownLinks.php`                     | markdown links                                | `ml`, `markdown`                          |
| `tools/branchNamePolicy.php`                  | branch name policy                            | `bnp`                                     |
| `tools/phpstanIgnoreJustification.php`        | ignoreErrors justification                    | `pij`                                     |
| `tools/phpstan.php`                           | PHPStan                                       | `stan`, `phpstan`                         |
| `tools/phpArkitect.php`                       | PHPArkitect                                   | `arch`, `arkitect`, `phparkitect`         |
| `tools/sensitiveParameterUsage.php`           | SensitiveParameter usage                      | `spu`                                     |
| `tools/phpunit.php`                           | PHPUnit                                       | `unit`, `phpunit`                         |
| `tools/infection.php`                         | Infection                                     | `infect`, `infection`                     |
| `tools/yamlLint.php`                          | Yaml Lint (when `symfony/yaml` is installed)  | `yaml`                                    |
| `tools/twigCsFixer.php`, `tools/twigLint.php` | Twig lanes (library-gated / Symfony platform) | `twigcs` / none                           |

`uniterate` and the `all*Tools` phase runners are not lanes and cannot be overridden.

### 5.2 A complete override

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

return new class implements ToolInterface {
    private const string IDENTIFIER = 'myproject.phpstan';

    public function name(): string
    {
        return 'phpstan';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $context->writeln('Running project PHPStan (level 8, custom neon)');

        $result = $context->php->withoutXdebug(
            $context->config->paths->pharDir . '/phpstan.phar',
            [
                'analyse',
                '--configuration=' . $context->configPath('phpstan.neon'),
                '--level=8',
                '--no-progress',
                ...$context->config->pathsToCheck,
            ],
            $context->config->paths->projectRoot,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        // PHPStan: 1 = findings, anything else = crash (never retried).
        return ToolResultDto::fromExitCode($result->exitCode, 'PHPStan', 1);
    }
};
```

Rules the runner enforces or relies on:

- `name()` must return the canonical name of the lane the file replaces.
- `run()` **never** calls `exit`, `die`, `echo`, `print`, `passthru`, `system`, `exec` or
  builds a shell string. It prints through `$context->writeln()` and runs commands through
  `$context->php` or `$context->processes`. The runner owns retries, aggregation and the exit
  code.
- A failing override ends its output with `$context->writeIdentifier(...)`, so the failure is
  attributable. The identifier is any stable dotted string; `vendor/bin/rule-doc` resolves the
  shipped `phpqaci.*` ones, a project one is simply printed.
- Return `ToolResultDto::failed()` for findings (retryable interactively),
  `ToolResultDto::crashed()` for a broken tool (never retried), `ToolResultDto::skipped()` when
  the tool decides not to run (counts as passed), `ToolResultDto::passed()` otherwise.
  `ToolResultDto::fromExitCode($code, $label, ...$failureCodes)` maps 0 to passed, the listed
  codes to failed, anything else to crashed.
- Honour `$context->config->readOnly`: a mutating tool must dry-run and return failed when it
  would have changed a file. `LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance::wouldModify($context, 'Name', 'alias')`
  prints the standard remediation block.

### 5.3 `ToolContext` reference

Everything an override or hook may use. All properties are public and readonly.

| Member                                                                                                                                      | Type                  | Use                                                                                                                                                   |
| ------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `$context->config`                                                                                                                          | `QaConfigDto`         | The resolved configuration of this run (below).                                                                                                       |
| `$context->config->paths`                                                                                                                   | `ProjectPathsDto`     | `projectRoot`, `libraryRoot`, `binDir`, `srcDir`, `testsDir`, `projectConfigDir`, `varDir`, `cacheDir`, `pharDir`, `configDefaultsDir`. All absolute. |
| `$context->config->pathsToCheck`                                                                                                            | `list<string>`        | Absolute paths the path-supporting tools scan (`tests/` + `src/`, or the `-p` path).                                                                  |
| `$context->config->pathsToIgnore`                                                                                                           | `list<string>`        | Project-relative excluded paths.                                                                                                                      |
| `$context->config->ci`, `->readOnly`, `->aggregate`, `->jsonOutput`                                                                         | `bool`                | Run modes.                                                                                                                                            |
| `$context->config->singleTool`, `->specifiedPath`                                                                                           | `?string`             | `-t` / `-p` as given.                                                                                                                                 |
| `$context->config->phpBinPath`, `->memoryLimit`, `->xdebugEnabled`, `->quickTests`, `->halfCpuThreads`                                      |                       | Host facts.                                                                                                                                           |
| `$context->config->phpUnit`                                                                                                                 | `PhpUnitOptionsDto`   | `coverage`, `quickTests`, `iterativeMode`.                                                                                                            |
| `$context->config->infection`                                                                                                               | `InfectionOptionsDto` | `enabled`, `threads`, `onlyCovered`, `minMsi`, `minCoveredMsi`, `diffBase`, `diffCoveredMsi`.                                                         |
| `$context->config->useArkitect`, `->arkitectExcludePaths`, `->useSensitiveParameterCheck`, `->twigDirectories`, `->yamlDirectories`         |                       | Lane settings.                                                                                                                                        |
| `$context->config->platform`                                                                                                                | `PlatformEnum`        | `Generic` or `Symfony`.                                                                                                                               |
| `$context->configPath('phpstan.neon')`                                                                                                      | `string`              | The three-level lookup; the generic path is returned even if absent.                                                                                  |
| `$context->configPaths->isProjectOverride('x')`                                                                                             | `bool`                | Whether the project supplies its own copy.                                                                                                            |
| `$context->php->withoutXdebug($script, $args, $cwd, $env = [], $streamOutput = true, $lowPriority = false)`                                 | `ProcessResultDto`    | Run a PHP script or PHAR with `XDEBUG_MODE=off` and the memory limit. The Bash `phpNoXdebug -f X -- args`.                                            |
| `$context->php->withXdebug($script, $args, $cwd, $env = [], $streamOutput = true)`                                                          | `ProcessResultDto`    | Xdebug left as the host runs it, for coverage; set `XDEBUG_MODE` in `$env`.                                                                           |
| `$context->php->version()`, `->hasXdebug()`                                                                                                 |                       | Host probes.                                                                                                                                          |
| `$context->processes->run(new ProcessSpecDto(command: [...], cwd: ..., env: [...], timeout: null, streamOutput: true, lowPriority: false))` | `ProcessResultDto`    | Any non-PHP command. `command` is an argv list, never a string.                                                                                       |
| `$context->logs->archive($toolName, $logDir, $logFileName, $pathSpecific, $pathsChecked)`                                                   | `void`                | The Bash `archiveToolLog`: timestamped copy, last ten kept per pattern.                                                                               |
| `$context->logDir('mytool_logs')`                                                                                                           | `string`              | A directory under `var/qa`, created on first use.                                                                                                     |
| `$context->writeln($line)`                                                                                                                  | `void`                | Print a line of tool output.                                                                                                                          |
| `$context->writeIdentifier($id)`                                                                                                            | `void`                | Print the standard failure trailer.                                                                                                                   |
| `$context->output`                                                                                                                          | `OutputInterface`     | Decoration stream (stderr in `--json` mode).                                                                                                          |
| `$context->stdout`                                                                                                                          | `OutputInterface`     | The real stdout, for structured output only.                                                                                                          |

`ProcessResultDto` has `exitCode`, `output` (stdout and stderr interleaved) and `succeeded()`.

### 5.4 Bash helper to PHP equivalent

| Bash-era helper or variable                                                     | PHP                                                                                                                           |
| ------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `phpNoXdebug -f "$pharDir"/x.phar -- args`                                      | `$context->php->withoutXdebug($context->config->paths->pharDir . '/x.phar', [args], $context->config->paths->projectRoot)`    |
| `"$phpBinPath" -f "$binDir"/phpunit -- args` with coverage                      | `$context->php->withXdebug($context->config->paths->binDir . '/phpunit', [args], $root, ['XDEBUG_MODE' => 'coverage'])`       |
| `configPath phpstan.neon`                                                       | `$context->configPath('phpstan.neon')`                                                                                        |
| `"${pathsToCheck[@]}"`                                                          | `...$context->config->pathsToCheck`                                                                                           |
| `"${pathsToIgnore[@]}"`                                                         | `$context->config->pathsToIgnore`                                                                                             |
| `$qaReadOnly == true`                                                           | `$context->config->readOnly`                                                                                                  |
| `$CI == true`                                                                   | `$context->config->ci`                                                                                                        |
| `$xdebugEnabled == 1`                                                           | `$context->config->xdebugEnabled`                                                                                             |
| `$qaHalfCpuThreads`                                                             | `$context->config->halfCpuThreads`                                                                                            |
| `reportReadOnlyWouldModify "Name" "alias"`                                      | `ReadOnlyGuidance::wouldModify($context, 'Name', 'alias')` then `return ToolResultDto::failed(...)`                           |
| `archiveToolLog "Name" "$dir" "file.log" "$specifiedPath" "${pathsToCheck[@]}"` | `$context->logs->archive('Name', $dir, 'file.log', null !== $context->config->specifiedPath, $context->config->pathsToCheck)` |
| `tryAgainOrAbort "Name"` / `qaSimpleTool` retry loops                           | Delete. Return `ToolResultDto::failed()`; the runner retries interactively.                                                   |
| `exit 1`                                                                        | `return ToolResultDto::failed('reason')`                                                                                      |
| `return 0` (skip)                                                               | `return ToolResultDto::skipped('reason')`                                                                                     |
| `echo "..."`                                                                    | `$context->writeln('...')`                                                                                                    |
| `set +e` / `set -e` / `${PIPESTATUS[0]}`                                        | Delete. `ProcessResultDto::$exitCode` is the tool's own code.                                                                 |
| `renice` / `oom_score_adj`                                                      | `lowPriority: true` on `withoutXdebug()` or `ProcessSpecDto`.                                                                 |

### 5.5 Wrapping the shipped lane instead of replacing it

Most Bash overrides only added a step before or after the standard tool. Delegate to the shipped
lane for the standard part:

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ShippedTools;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

return new class implements ToolInterface {
    public function name(): string
    {
        return 'phpunit';
    }

    public function identifier(): string
    {
        return 'myproject.phpunit';
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $context->writeln('Resetting the test database');
        $reset = $context->php->withoutXdebug(
            $context->config->paths->projectRoot . '/bin/console',
            ['doctrine:schema:drop', '--force', '--env=test'],
            $context->config->paths->projectRoot,
        );
        if (!$reset->succeeded()) {
            $context->writeIdentifier($this->identifier());

            return ToolResultDto::crashed('test database reset failed');
        }

        return ShippedTools::all()['phpunit']->run($context);
    }
};
```

If the only goal was to skip the lane for this project, do not write an override. Use the
setting (`withInfection(false)`, `withArkitect(false)`, `withSensitiveParameterCheck(false)`),
or return `ToolResultDto::skipped('...')` from a two-line override for lanes without a switch.

Done when: every `tools/*.inc.bash` is deleted, each replacement `tools/<name>.php` returns a
`ToolInterface`, and `vendor/bin/qa -t <name>` runs it (the run prints the override's own
output, not the shipped banner text).

## 6. `hookPre.bash` / `hookPost.bash` become `hookPre.php` / `hookPost.php`

Each file returns a callable that receives the `ToolContext`. The pre hook runs after the
configuration is built and the PHARs verified, before the run lock is taken and the first tool
runs. The post hook runs only after every tool passed and PHPLoc has printed.

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\ToolContext;

return static function (ToolContext $context): void {
    $context->writeln('Checking managed source is current');
    $result = $context->php->withoutXdebug(
        $context->config->paths->binDir . '/managed-source',
        ['check'],
        $context->config->paths->projectRoot,
    );
    if (!$result->succeeded()) {
        throw new RuntimeException('managed source is stale: run composer install');
    }
};
```

Rules:

- To fail the run from a hook, throw. The exception message is the failure report.
- A hook uses the same `ToolContext` API as an override (section 5.3); the same "never `exit`,
  never a shell string" rule applies.
- The hooks are optional. Delete the `.bash` files whether or not you add `.php` ones.

Done when: no `hook*.bash` remains and `vendor/bin/qa -t pt` shows "Running pre hook" (when a
pre hook exists) before the tool output.

## 7. Verify

Run the full battery. Writable is the default and is what you want here — the migration
leaves Rector and PHP CS Fixer work to do, and a writable run applies it:

```bash
vendor/bin/qa
```

Only once that is green and committed, confirm the committed tree the way CI will see it:

```bash
QA_READONLY=1 vendor/bin/qa
```

Then, if the project has overrides or hooks, run each one directly:

```bash
CI=true vendor/bin/qa -t <name>
```

Errors you can see and what each one means:

| Message (abridged)                                                                | Cause                                                                                  | Fix                                                                                                   |
| --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `qaConfig/qaConfig.inc.bash is no longer read: configuration is PHP now`          | The Bash config still exists.                                                          | Finish section 4 and delete the file.                                                                 |
| `qaConfig/tools/<t>.inc.bash is no longer sourced: tool overrides are PHP now`    | A Bash override still exists for `<t>`.                                                | Finish section 5 for that tool and delete the file.                                                   |
| `qaConfig/hookPre.bash is no longer run: hooks are PHP now` (or `hookPost`)       | A Bash hook still exists.                                                              | Finish section 6 and delete the file.                                                                 |
| `qa.php must return a closure taking and returning a QaConfigBuilder, got <type>` | The file does not `return` a callable.                                                 | Return the closure.                                                                                   |
| `qa.php must return the adjusted QaConfigBuilder, got <type>`                     | The closure returned `null`, `void` or `$qa` after a chain whose result was discarded. | Return the result of the `with*()` chain.                                                             |
| `Call to undefined method ...QaConfigBuilder::withX()`                            | A misspelt or non-existent setting.                                                    | Use a method from the table in 4.2.                                                                   |
| `tools/<t>.php must return a LTS\PHPQA\Pipeline\Tool\ToolInterface, got <type>`   | The override file returns the wrong thing.                                             | Return an object implementing the interface.                                                          |
| `hookPre.php must return a callable that accepts a ToolContext, got <type>`       | The hook file returns the wrong thing.                                                 | Return a closure.                                                                                     |
| `Invalid tool: <name>` on `-t`                                                    | The override file name is fine but `-t` needs an alias or canonical name.              | Use a value from the aliases column in 5.1.                                                           |
| `Another QA run holds the lock`                                                   | A concurrent run.                                                                      | Wait; the lock goes stale after ten minutes of inactivity. Never delete `qaConfig/.qa-lock/` by hand. |
| A fixer reports pending changes in a READ-ONLY run                                | Expected in CI when files need fixing.                                                 | `QA_READONLY=0 vendor/bin/qa -t fixer` (or `-t rector`), commit, re-run.                              |

Done when: the full read-only battery exits 0 and prints `ALL TESTS PASSING`.

## 8. Commit

One commit containing the deletions of every Bash-era file and the additions of every PHP
replacement, so the switch is atomic and a checkout at any commit runs:

```bash
git add -A qaConfig
git commit -m 'Migrate qaConfig to the php-qa-ci 8.5 PHP pipeline'
```

Do not leave the Bash files in place "for reference": the pipeline refuses to run while any
of them exists.

## 9. Do not

- Do not copy the shipped lane source into `qaConfig/tools/` to change one argument. Wrap it
  (section 5.5) or, for a configuration change, put the tool's config file under `qaConfig/`.
- Do not set `QA_READONLY`, `CI` or `GITHUB_ACTIONS` from `qa.php`; they are decided before the
  file is read.
- Do not `putenv()` or mutate `$_ENV` in `qa.php`, an override or a hook to influence a later
  lane. Use the builder methods and the context.
- Do not reach past the supported extension surface. The types named on this page
  (`QaConfigBuilder`, `ToolInterface`, `ToolContext`, `ToolResultDto`, `ShippedTools`,
  `ReadOnlyGuidance`, the config and process DTOs, `PhpInvoker`, `ProcessRunnerInterface`,
  `LogArchiver`, `ConfigPathResolver`, `PlatformEnum`, and the pipeline-assembly types
  `PipelineBuilder`, `PhaseDto`, `PhaseEnum`, `ToolDefinitionDto`, `ToolGateEnum`,
  `UnknownPhaseException`) are tagged `@api`; everything else under
  `LTS\PHPQA\Pipeline` is `@internal`, and PHPStan reports any use of an internal type from a
  consumer's own code. Keep this code under `qaConfig/`; the shipped PHPArkitect consumer
  boundary factory is available if you want the same rule at namespace level in `src/`.
- Do not lower Infection floors during the migration. The floors are a ratchet; carry the
  numbers across unchanged.
