# php-qa-ci architecture, for contributors

How the pipeline is built and the invariants a change must keep. What a consumer observes (phase
order, preparation, configuration, each lane's behaviour) is canonical in `docs/`, per the
deviation recorded in [DocumentationStrategy.md](DocumentationStrategy.md); this file links there
rather than restating it.

## Bash is for invocation, PHP is for logic (Owner ruling, non-negotiable)

A shell script may locate things, set environment variables and hand off. The moment there is
real work — branching on state, parsing a response, unpacking an archive, deciding between
outcomes — it belongs in a class under `src/` with a thin `bin/` entry point, because that is the
only form the test suite and PHPStan can see. Untested Bash logic is a blind spot inside a
quality tool.

The worked example is the ShellCheck updater: the decision table is
[`InstallDecider`](../src/ShellCheck/InstallDecider.php) with unit tests per branch, the I/O is
[`ShellCheckInstaller`](../src/ShellCheck/ShellCheckInstaller.php) (an HTTP fetch and a
`PharData` extract, nothing shelled out), the entry point is `bin/shellcheck-install`, and
`scripts/tool-install.bash` contributes one `php` call. Prefer this shape over adding to a
`scripts/*.bash`.

Bash therefore survives only as thin wrappers (`ci.bash`, the `bin/` redirect stubs) and the
invocation shell of the maintainer scripts under `scripts/`.

## Core components

1. **Entrypoint**: `bin/qa` boots [QaApplication](../src/Pipeline/Cli/QaApplication.php), the
   composition root that parses arguments, reads the environment, builds the configuration and
   runs the pipeline.
2. **Tool registry**: [ToolRegistry](../src/Pipeline/Tool/ToolRegistry.php) is the single source
   of truth for tool names, `-t` aliases, phase membership and order, path support, gates and
   banners; the CLI usage text (`bin/qa -h`) and the active-defences region are derived from it.
   Each phase is a [PhaseDto](../src/Pipeline/Tool/Dto/PhaseDto.php) that contributes its own
   `all*` runner.
3. **Lanes**: one `ToolInterface` class per tool under [src/Pipeline/Lane/](../src/Pipeline/Lane/),
   wired by name in [ShippedTools](../src/Pipeline/Tool/ShippedTools.php). **A new tool extends
   the public CLI/agent API** (`-t` tokens, help text, pipeline order, a stable identifier, a docs
   page), so whether a check is a new tool or an assertion inside an existing one is a considered
   decision: [tool-boundaries.md](tool-boundaries.md).
4. **Pipeline builder**: [PipelineBuilder](../src/Pipeline/Tool/PipelineBuilder.php) assembles the
   registry and the lane map the run uses; `defaults()` is the shipped pipeline, and immutable
   withers add a phase, set the phase order or add a tool without forking the registry. A project
   extends it from `qaConfig/pipeline.php`
   ([docs/extending-the-pipeline.md](../docs/extending-the-pipeline.md)).
5. **Runner**: [Pipeline](../src/Pipeline/Runner/Pipeline.php) walks the phases and applies the
   gate, retry and aggregate policies; [ToolExecutor](../src/Pipeline/Runner/ToolExecutor.php)
   runs one lane with the retry prompt.
6. **Configuration**: a typed, immutable [QaConfigBuilder](../src/Pipeline/Config/QaConfigBuilder.php)
   seeded from defaults and environment variables, adjusted by the project's `qaConfig/qa.php`;
   config files resolve through [ConfigPathResolver](../src/Pipeline/Config/ConfigPathResolver.php).
7. **Platform detection**: [PlatformDetector](../src/Pipeline/Config/PlatformDetector.php)
   recognises Symfony (via `symfony.lock`); everything else is generic.

## A run, end to end

1. `bin/qa` locates the project's `vendor/autoload.php`; the project root is its parent directory
   and the library root is the directory `bin/qa` lives in.
2. `QaApplication` parses the arguments, reads the environment, resolves the project paths and
   detects the platform.
3. The configuration is built from the shipped defaults, then adjusted by `qaConfig/qa.php` if
   present.
4. Tools run from the library's `vendor-phar/` (never from php-qa-ci's own `vendor/`, and not from
   the Composer bin dir: `phpqaci.binDirTool` forbids that). The exceptions are the project's own
   test runners: `phpunit`, and `paratest` when installed, come from the project's bin dir.
5. The phases execute in an order designed to modify code first, then validate it. The steps and
   their order are in [docs/pipeline.md](../docs/pipeline.md); the roster is `bin/qa -h`.

The project's bin dir is `config.bin-dir` in `composer.json`, `vendor/bin` by default; examples
assume the default.

## The tool runner

[ToolExecutor](../src/Pipeline/Runner/ToolExecutor.php) runs one lane at a time:

1. [ShippedToolLocator](../src/Pipeline/Runner/ShippedToolLocator.php) resolves the lane by its
   canonical name: `{project}/qaConfig/tools/{toolName}.php` (a project override returning a
   `ToolInterface`), else the shipped lane from `ShippedTools::all()`. A Bash-era
   `tools/{toolName}.inc.bash` is refused with migration guidance rather than ignored.
2. The lane's `run(ToolContext)` prints its own detail to the context's output and returns a
   [ToolResultDto](../src/Pipeline/Tool/Dto/ToolResultDto.php): passed, failed, crashed or
   skipped.
3. On a failure the executor prints the failure banner and, in an interactive run, asks "try
   again? (y/n)" ([ConsoleRetryPrompt](../src/Pipeline/Runner/ConsoleRetryPrompt.php)); in CI
   mode the answer is always no. A crash (an exit code outside the tool's documented pass/fail
   set) is never retried.

Invariants a lane keeps:

- A lane never calls `exit` and never builds a shell string. It prints through
  `$context->writeln()` and runs commands through `$context->php` (PHP scripts and PHARs, with
  Xdebug off and the memory limit applied) or `$context->processes` (any other command).
- A lane finds its config file through `$context->configPath('<file>')` on its
  [ToolContext](../src/Pipeline/Tool/ToolContext.php) (project `qaConfig/`, then
  `configDefaults/{platform}/`, then `configDefaults/generic/`). The generic path is returned
  even when it does not exist, so a lane can report the path it looked for.
- A failing lane ends with its stable identifier (`phpqaci.<lane>`, resolved by `rule-doc`).
- The retry, aggregate and exit-code policies belong to the runner, not to the lanes.

## The pipeline is not a tool proxy (Owner ruling)

The pipeline enforces one standard configuration per tool across every project; it is not a
proxy that passes arbitrary arguments through to the tools. For a contributor that means:

- Do not add pass-through flags or per-run tool arguments to `bin/qa`. `-t`, `-p`, `--json` and
  `--agent-mode` are the whole interface; a need for another flag is a configuration setting
  (a typed `with*()` on `QaConfigBuilder`) or a config file under `qaConfig/`.
- The pipeline owns every tool argument, the PHAR delivery, the cache directories, the Xdebug
  mode and the memory limit, so that a tool behaves the same in every consumer.
- The consumer's side of the same ruling — what to do instead when a tool needs a flag the
  pipeline does not offer — is in [docs/configuration.md](../docs/configuration.md#the-pipeline-is-not-a-tool-proxy).
