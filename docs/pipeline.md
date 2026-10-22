# PHPQA Pipeline

PHPQA runs tools in a sequence designed to fail as quickly as possible, making it ideal for both local development and CI.

The tools are organised into four phases. Code modification runs first (so later phases validate the final state of the code), followed by linting, static analysis, and testing. There is no point running static analysis on code that has not yet been auto-fixed, and no point running tests if the code has syntax errors.

The pipeline is PHP. `bin/qa` boots [QaApplication](../src/Pipeline/Cli/QaApplication.php), which builds the configuration, wires the services and hands a [ToolContext](../src/Pipeline/Tool/ToolContext.php) to the [Pipeline](../src/Pipeline/Runner/Pipeline.php) runner. Each tool is a lane: one `ToolInterface` class under [src/Pipeline/Lane/](../src/Pipeline/Lane/), located by name through [ShippedToolLocator](../src/Pipeline/Runner/ShippedToolLocator.php) so a project's `qaConfig/tools/<name>.php` can replace it, and run by [ToolExecutor](../src/Pipeline/Runner/ToolExecutor.php).

## Fail-fast, aggregate and retries

A writable run (the local default) is **fail-fast**: the first failing tool ends the run with exit code 1. A read-only run (`QA_READONLY=1`, and GitHub Actions by default) is **aggregate**: every tool runs, the failures are collected and reported together at the end, and the exit code is 1 if any failed. `QA_FAIL_FAST=1` forces fail-fast in a read-only run. See [Configuration](./configuration.md) for the environment variables.

### Exit codes

| Code | Meaning                                              |
| ---- | ---------------------------------------------------- |
| 0    | every tool passed                                    |
| 1    | a tool failed, or the run could not be configured    |
| 75   | another QA run held the lock, so nothing was checked |

75 exists so a caller can tell contention from a verdict. A run that hits it also prints one line to stderr and checks nothing; retry it rather than reporting it as a failure.

A tool whose process is killed by a signal it did not ask for has **crashed**, whatever its exit code would have meant. The run prints `!! <command> ended with exit code 143, killed by signal 15 (SIGTERM)` (128 + the signal, as a shell reports it), and the tool's failure banner says `<tool> crashed:` with the same description. A crash is never retried, and the run exits 1 as for any failed tool.

A **segmentation fault** (SIGSEGV, exit 139) is never retried past and never accepted in a passing run, so it **halts the run** at once, in fail-fast and aggregate mode alike, even in the copy/paste report: `HALTED: <tool> crashed with a segmentation fault, so no further tool runs.`, followed by a line pointing at [docs/segfaults.md](segfaults.md) in the installed package, on finding what crashed and where to report it. A process that exits 139 itself halts the run the same way. A shell does this when the command it ran crashed, and so does a tool that re-runs itself and passes on its copy's crash. Such a process was not killed, and is not called so: it is named as `<command> ended with exit code 139, which it exited with itself: the code a shell gives for signal 11 (SIGSEGV), so a process below it crashed ...`, and the tool counts as crashed even if it reported a pass. A process outside any tool, a start-up probe or a [hook](#hooks), stops the run the same way when a signal kills it or it exits 139. The halt says which: `HALTED: a process run <where> was killed by a signal, so the run stops here:`, or `reported a crash below it` in place of `was killed by a signal`, followed by each command and how it ended. The exit code is 1.

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

The pre hook runs after configuration and PHAR verification, before the run lock and the first tool. The post hook runs only after every tool passed and PHPCPD has printed its statistics. To fail the whole process from a hook, throw an exception. A Bash-era `hookPre.bash` / `hookPost.bash` is refused with a message pointing at [Upgrading to 8.5](./upgrading-to-8.5.md).

### Suggested Use Cases

#### hookPre.php

- Flushing and/or priming caches
- Building IDE helpers
- Updating composer dependencies

#### hookPost.php

- Pushing code to CI
- Rebuilding example code
- Generating reports, such as coverage reports
- Sending notifications
- Deploying artefacts
- Collecting custom metrics

## The Pipeline

### 1. Detection and Configuration

`QaApplication` performs these steps in order:

- **Arguments** ([ArgumentsParser](../src/Pipeline/Cli/ArgumentsParser.php)): `-t <tool>`, `-p <path>` (or a single bare path), `--json` (PHPStan only), `--agent-mode` (a terse stdout plus a per-file JSON report; see [Agent mode](agent-mode.md)), `-h`. A path given to a tool that does not support paths, an unknown tool or an unknown option prints the usage and exits 1. `-t` and `-p` are each given at most once: a second `-t`, a second `-p`, a second bare path or a bare path beside `-p` is refused with exit 1 rather than one silently replacing the other, and a repeated option names both values.
- **Environment** ([EnvironmentReader](../src/Pipeline/Config/EnvironmentReader.php)): decides CI, read-only and aggregate mode and announces them.
- **Project paths** ([ProjectPathsResolver](../src/Pipeline/Config/ProjectPathsResolver.php)): requires `src/` and `tests/` (or `test/`); reads `config.bin-dir` from `composer.json` (default `vendor/bin`); fixes `var/qa`, `var/qa/cache`, `qaConfig/` and the library's `vendor-phar/` and `configDefaults/`.
- **Platform detection** ([PlatformDetector](../src/Pipeline/Config/PlatformDetector.php)): Symfony via `symfony.lock`, otherwise generic. See [Platform Detection](./platform-detection.md).
- **Xdebug probe**: whether coverage and mutation testing are available. With Xdebug loaded, `XDEBUG_MODE` is set to `coverage` unless it is already `debug`.
- **Pipeline**: `PipelineBuilder::defaults()` is the shipped phases and tools; [PipelineConfigLoader](../src/Pipeline/Config/PipelineConfigLoader.php) applies your `qaConfig/pipeline.php`, which can add phases and tools (see [extending the pipeline](extending-the-pipeline.md)). This happens before the arguments are parsed, so `-t` can name a project-registered tool or phase.
- **Configuration**: `QaConfigBuilder::defaults()` seeds every setting from the shipped defaults and the environment variables; [ProjectConfigLoader](../src/Pipeline/Config/ProjectConfigLoader.php) applies your `qaConfig/qa.php`; `build()` derives the dependent values (coverage needs Xdebug, Infection needs coverage). A leftover `qaConfig.inc.bash` is refused with migration guidance.

### 2. Preparation

`Pipeline::run()` then:

- creates `var/qa/` and `var/qa/cache/` with self-excluding `.gitignore` files and adds the managed block of QA runtime-cache excludes to the project's root `.gitignore` ([DirectoryPreparer](../src/Pipeline/Runner/DirectoryPreparer.php));
- verifies the PHARs ([PharToolsVerifier](../src/Pipeline/Runner/PharToolsVerifier.php)): `phive.xml` is a hard requirement, and every PHAR it lists plus one committed `vendor-phar/<tool>.phar` per `build/<tool>/` manifest must be present under the library's `vendor-phar/`, and so must `vendor-phar/turbo-ext.json` for the same PHPStan version as the shipped phar (its digests pin the Turbo files of the release it names). Nothing is fetched at run time; PHIVE re-fetches only in the maintainer `update`/`--force` modes of `scripts/tool-install.bash`, and maintainers rebuild the self-built PHARs with `scripts/build-phar.bash`;
- runs your project's `hookPre.php` if present;
- acquires the run lock ([RunLock](../src/Pipeline/Lock/RunLock.php)). One run per project: an exclusive `flock` on `qaConfig/.qa-lock/qa-running.lock`, with the holder's host, pid, tool, path and start time recorded in the file. A holder aborts the run before any tool executes, with exit code 75. The kernel frees the lock the moment its holder dies, however it dies, so there is no stale window; a record left by a killed run is taken over with a note. The lock is released with the exit code and elapsed time at the end. An interrupted run (Ctrl-C, `kill`, a closed terminal: SIGINT, SIGTERM, SIGHUP or SIGQUIT) stops its running tool and that tool's own descendants, read from `/proc` by [ProcessTree](../src/Pipeline/Process/ProcessTree.php) so PHPStan's workers go too, releases the lock and exits 128 + the signal number ([RunInterruptHandler](../src/Pipeline/Runner/RunInterruptHandler.php)). This is why `ext-pcntl` and `ext-posix` are required.

A full run (no `-t`) then checks that the locked packages you keep current, `lts/php-qa-ci` by
default, are what `composer update` would install: [Dependency Freshness](./tools/dependencyFreshness.md).
Stale dependencies end the run before any phase, in aggregate mode too. When Composer cannot say
(offline, a source unreachable), the check prints a named `SKIPPED dependencyFreshness` line and
the run carries on.

### 3. QA Tools (Four Phases)

The phases, the tools in each, their order and the `-t` aliases are owned by [ToolRegistry](../src/Pipeline/Tool/ToolRegistry.php) and frozen by a characterisation test. The run reads them through [PipelineBuilder](../src/Pipeline/Tool/PipelineBuilder.php), whose `defaults()` is exactly this shipped set. They are not listed by hand here, because a hand-kept list drifts from the registry:

- `vendor/bin/qa -h` prints every tool in pipeline order with its aliases;
- the [identifier index](phpstan-rules/README.md#pipeline-lanes) lists every lane with its identifier, alias and documentation page;
- each tool's page under [tools/](tools/) says what it checks, when it runs and how to configure it.

#### Phase 1: Code Modification

The tools that rewrite code (Rector, PHP CS Fixer, Twig CS Fixer, Markdown Format) run first, so every later phase validates the final state of the code. In a writable run, the full pipeline and `-t allCS` repeat this phase until a pass changes no file under the checked paths, at most three passes ([RepeatUntilSettled](../src/Pipeline/Runner/RepeatUntilSettled.php)): a tool never sees what the tools after it wrote, and PHP CS Fixer's `final_class`, for example, creates work for Rector. In a read-only run the tools report a pending change as a failure instead of applying it.

#### Phase 2: Linting and Validation

Cheap checks of structure, dependencies, syntax and documentation, so that static analysis never runs on code that does not parse or on a broken autoloader. These validate rather than modify, with two exceptions in a writable run: PHP Strict Types adds a missing `declare(strict_types=1)`, and Composer Checks normalizes `composer.json`.

On a Symfony project the platform lane **[Twig Lint](./tools/twigLint.md)** is appended to this phase. It is not `-t` selectable.

#### Phase 3: Static Analysis

PHPStan and the checks around it. Branch Name Policy runs first in this phase.

#### Phase 4: Testing

PHPUnit, then Infection's mutation testing.

#### Gates

Each tool's [ToolGateEnum](../src/Pipeline/Tool/ToolGateEnum.php) gate is declared on its registry row. A `NotQuick` tool is skipped when `phpqaQuickTests=1`; an `Infection` tool is skipped when quick tests are on or Infection is disabled. Gates apply to phase runs, not to a single tool selected with `-t`.

### 4. Copy/Paste Report and Post Hook

If all tests pass, [PHPCPD](./tools/phpcpd.md) reports duplicated code. It is informational and cannot fail the run.

The pipeline then runs your project's `hookPost.php` if present, prints the retry warning if any tool was retried, releases the lock with the elapsed time, and ends with a completion message naming the host.

If there were retries of any of the tools, it is strongly suggested that you rerun the full pipeline before regarding it as passing.
