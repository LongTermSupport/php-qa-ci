# Docs rewrite for the PHP pipeline (Task 4.3)

Agent: docs rewrite (Fable 5.1). Nothing committed.

## Files rewritten

- `CLAUDE.md`: Overview, Architecture, How It Works, Pipeline Execution Order (preflight now the
  QaApplication/Pipeline sequence: arguments, environment, project paths, platform, Xdebug probe,
  QaConfigBuilder + ProjectConfigLoader, DirectoryPreparer, PharToolsVerifier, hookPre.php,
  RunLock with the 600 s stale window), the four phases with Symfony platform lanes and the
  ToolGateEnum gates, Configuration System (ConfigPathResolver 3-level lookup; values via the
  builder with an env-var/builder-method table), Platform Detection, Tool Runner System
  (ShippedToolLocator, ToolExecutor retry/crash policy), Hook System (hookPre.php/hookPost.php
  callables, `qaConfig/tools/<name>.php` override example), Common Customizations, Overriding Tool
  Configurations, every Tools Reference entry (`**Lane**: src/Pipeline/Lane/XTool.php` plus a
  docs/tools link; Twig/Yaml lint entry added), Important Notes. Untouched: Knowledge & Memory
  Policy, dogfooding section, Claude Code Hooks, Managed Source, Environment Requirements, Design
  Philosophy, the `<hooksdaemon>` block.
- `README.md`: "written in Bash" dropped; new "Architecture" section linking docs/pipeline.md and
  docs/upgrading-to-8.5.md; Tool Delivery gains the in-process checks and the run-time PHAR
  verification; PHPArkitect section uses `withArkitect(false)` / `withArkitectExcludedPaths()`;
  SensitiveParameter opt-out uses `withSensitiveParameterCheck(false)` and links this repo's
  `qaConfig/qa.php`; docs index gains the upgrade guide.
- `docs/pipeline.md`: full rewrite (fail-fast vs aggregate, retry prompt, crash never retried,
  PHP hooks, preflight sequence, phases linked to every docs/tools page, gates, lock release).
- `docs/phpqa-tools.md`: full rewrite; every tool links its lane class and its docs/tools page;
  tool-runner section describes ShippedToolLocator and `qaConfig/tools/<name>.php`; Twig/Yaml
  lanes documented as Symfony platform lanes appended to the linting phase.
- `docs/platform-detection.md`: PlatformDetector/PlatformEnum, platform lanes, twig/yaml
  directories via `withTwigDirectories()` / `withYamlDirectories()`, ConfigPathResolver rung.
- `CLAUDE/prepush-verification.md`: battery keeps `QA_READONLY=1 CI=true bin/qa` and the
  `-t` forms, notes the PHP entrypoint and the run lock, scope gotcha now cites `qaConfig/qa.php`.
- `.github/workflows/ci.yml`: ShellCheck job no longer lists `bin/qa` and no longer walks
  `includes`; comment describes what remains (ci.bash, bin stubs + shared include, git hook,
  scripts/, qaConfig/).
- `docs/ci.md`: no Bash internals; left as is.

## Outside the owned list (minimal, needed for the verify gate)

- `docs/configuration.md`: the `includes/functions.inc.bash` link became a ConfigPathResolver
  link; the `qaConfig.inc.bash` memory-limit example became a `qa.php` snippet; one prose
  mention of `configPath()` renamed.
- `docs/github-actions.md`: the `qaConfig.inc.bash` bullet became `qaConfig/qa.php`.

## Verification

- `grep -rn 'includes/' README.md CLAUDE.md docs CLAUDE/prepush-verification.md .github/workflows/ci.yml`
  returns nothing outside `docs/upgrading-to-8.5.md`.
- `CI=true bin/qa -t ml` exits 0 (`untracked/scratch/docs-ml.log`).

Remaining `inc.bash` / `hook*.bash` mentions in the owned files describe only the refusal-with-
guidance behaviour of the PHP loaders, matching `LegacyBashConfigException`.
