# PHPQA Tools

QA runs a suite of standard tools, each implemented as a lane class under [src/Pipeline/Lane/](../src/Pipeline/Lane/). The lanes are wired by canonical name in [ShippedTools](../src/Pipeline/Tool/ShippedTools.php); their `-t` aliases, phase order and gates are owned by [ToolRegistry](../src/Pipeline/Tool/ToolRegistry.php).

The tools are run in a specific order across four phases, designed to modify code first (Phase 1), then validate (Phase 2), analyse (Phase 3), and test (Phase 4). The pipeline fails as quickly as possible.

In local development, a failed tool can be retried indefinitely. In CI, a failed tool fails the whole process (in a read-only run the remaining tools still run and the failures are reported together; see [the pipeline](./pipeline.md#fail-fast-aggregate-and-retries)).

## The Tool Runner

Each tool is located by [ShippedToolLocator](../src/Pipeline/Runner/ShippedToolLocator.php) and run by [ToolExecutor](../src/Pipeline/Runner/ToolExecutor.php):

- It first checks for a project override at `/project/root/qaConfig/tools/{toolName}.php`, a file returning a `ToolInterface`
- Otherwise it runs the shipped lane

An override receives the same [ToolContext](../src/Pipeline/Tool/ToolContext.php) as the shipped lane (the built configuration, the config-path resolver, the process runner, the PHP invoker and the console output) and returns a `ToolResultDto`. It never calls `exit` or builds a shell string. A worked example is in [Upgrading to 8.5](./upgrading-to-8.5.md#3-qaconfigtoolstoolincbash-becomes-qaconfigtoolstoolphp). A Bash-era `tools/{toolName}.inc.bash` is refused with migration guidance.

The detected platform does not change which lane class runs; it adds lanes. On a Symfony project the twig and yaml linters are appended to the linting phase (see [Platform Detection](./platform-detection.md)).

Every lane prints a stable identifier (`phpqaci.<lane>`) when it fails; `vendor/bin/rule-doc <identifier>` resolves it to its page under [docs/tools/](./tools/).

## Phase 1: Code Modification

These tools can modify your source files in a writable run. In a read-only run (`QA_READONLY=1`) they run in dry-run mode and a pending change fails the gate.

### Rector

[RectorTool](../src/Pipeline/Lane/RectorTool.php) -- [docs/tools/rector.md](./tools/rector.md)

Rector performs automated refactoring and code upgrades. It is delivered as a committed, self-contained PHAR (`vendor-phar/rector.phar`) built by `scripts/build-phar.bash rector` from the `build/rector/` manifest. The PHAR bundles its own *extracted* `phpstan/phpstan`, so Rector's PHPStan dependency never leaks into any consuming project's dependencies (and, being its own process, never collides with the pipeline's `phpstan.phar`).

The pipeline runs Rector in three stages:

1. **Safe Functions** -- Converts standard PHP functions to their [thecodingmachine/safe](https://github.com/thecodingmachine/safe) equivalents (which throw exceptions instead of returning false). Requires `thecodingmachine/safe` as a production dependency.
2. **PHPUnit** -- Applies PHPUnit-specific rector rules to the test directory.
3. **PHP 8.5** -- Applies PHP 8.5 migration rules (skipped if a project-specific `rector.php` or `qaConfig/rector.php` is found, as it is assumed those handle version upgrades).

Default configurations:

- [rector-safe.php](../configDefaults/generic/rector-safe.php)
- [rector-phpunit.php](../configDefaults/generic/rector-phpunit.php)
- [rector-php85.php](../configDefaults/generic/rector-php85.php)

### PHP CS Fixer

[PhpCsFixerTool](../src/Pipeline/Lane/PhpCsFixerTool.php) -- [docs/tools/phpCsFixer.md](./tools/phpCsFixer.md)

PHP CS Fixer automatically fixes code style issues according to modern PHP standards. It runs as a **PHAR** from `vendor-phar/php-cs-fixer.phar` (not as a Composer dependency).

The default configuration includes `@PHP8x5Migration` rules for PHP 8.5 compatibility, including nullable type declarations.

Please see the [PHPQA Coding Standards docs](./coding-standards.md) for configuration details.

See the [PHP CS Fixer Docs](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) for more information.

## Phase 2: Linting and Validation

These tools validate code without modifying it.

### PSR-4 Validation

[Psr4ValidateTool](../src/Pipeline/Lane/Psr4ValidateTool.php) -- [docs/tools/psr4Validate.md](./tools/psr4Validate.md)

Checks for code whose namespaces do not comply with the PSR-4 standard. Runs in-process; `bin/psr4-validate` remains for standalone use.

#### Ignore List

You can specify files or directories to be ignored by the validator. This is a newline-separated list of valid regex including a valid regex delimiter. For example:

```
#tests/bootstrap\.php#
#tests/phpstan-bootstrap\.php#
#tests/assets/.*?asset#
```

### Composer Checks

[ComposerChecksTool](../src/Pipeline/Lane/ComposerChecksTool.php) -- [docs/tools/composerChecks.md](./tools/composerChecks.md)

- Runs `composer diagnose` to check for issues (informational, never fails the run)
- Runs the shipped composer-normalize PHAR to normalize `composer.json` (dry-run in a read-only run, where a pending change fails the gate); no plugin or allow-plugins entry is needed
- Dumps the autoloader to ensure recent code changes will not cause autoloading issues

### Package Type Declaration

[PackageTypeTool](../src/Pipeline/Lane/PackageTypeTool.php)

Always-on check that requires `composer.json` to declare an explicit `type`. Runs immediately
after Composer Checks. See [tools/packageType.md](./tools/packageType.md) for details.

### Config Template Ignore-List Audit

[ConfigTemplateIgnoreListTool](../src/Pipeline/Lane/ConfigTemplateIgnoreListTool.php)

Always-on self-check that php-qa-ci runs against its own shipped `configDefaults/generic/`: every
namespace-less config template a consumer is documented to copy into `qaConfig/` must be matched
by a pattern in `psr4-validate-ignore-list.txt`, or the documented override fails `psr4Validate`.
Identifier `phpqaci.configTemplateIgnoreList`. See
[tools/configTemplateIgnoreListCheck.md](./tools/configTemplateIgnoreListCheck.md).

### Infection Config Source Directories Check

[InfectionConfigSourceDirsTool](../src/Pipeline/Lane/InfectionConfigSourceDirsTool.php)

Always-on check that every entry in infection.json's `source.directories` resolves, relative to
infection.json's own directory, to a real directory. Identifier `phpqaci.infectionConfigSourceDirs`.
See [tools/infectionConfigSourceDirs.md](./tools/infectionConfigSourceDirs.md).

### Version Pins Check

[VersionPinsTool](../src/Pipeline/Lane/VersionPinsTool.php)

Always-on check that every version pin in the QA configuration matches the toolchain in use:
phpunit.xml's schema URL and `SYMFONY_PHPUNIT_VERSION` against the installed PHPUnit major,
composer-require-checker's `thecodingmachine/safe` scan-files against the generated files safe
loads on the running PHP, and GitHub Actions workflows' PHP version detection against the PHP
`composer.json` requires. Runs immediately after the Infection Config Source Directories Check;
standalone alias `-t vp`. Identifier `phpqaci.versionPins`. See
[tools/versionPins.md](./tools/versionPins.md).

### Strict Types Enforcement

[PhpStrictTypesTool](../src/Pipeline/Lane/PhpStrictTypesTool.php) -- [docs/tools/phpStrictTypes.md](./tools/phpStrictTypes.md)

Scans `.php` and `.phtml` files under the checked paths for a missing `declare(strict_types=1)`.
On a read-only run it reports every offending file and fails; on a writable run it adds the
declaration to the opening `<?php` tag automatically (a file with no opening tag fails the gate).
There is no interactive prompt.

### PHP Parallel Lint

[PhpLintTool](../src/Pipeline/Lane/PhpLintTool.php) -- [docs/tools/phpLint.md](./tools/phpLint.md)

Very fast PHP linting process. Checks for syntax errors in your PHP files. Runs the shipped
`vendor-phar/parallel-lint.phar` (PHIVE-managed; upstream publishes the asset unsigned).

See the [PHP Parallel Lint project page](https://github.com/php-parallel-lint/PHP-Parallel-Lint) for more information.

### OPcache

[OpcacheTool](../src/Pipeline/Lane/OpcacheTool.php) -- [docs/tools/opcache.md](./tools/opcache.md)

Compiles every checked file through OPcache (never executing it) and asserts the bytecode is
free of the OPcache codegen defects php-qa-ci knows about. Where the linter asks whether the
source parses, this asks what the compiler emitted from it, which is where an optimizer defect
turns valid source into a crash.

### Composer Require Checker

[ComposerRequireCheckerTool](../src/Pipeline/Lane/ComposerRequireCheckerTool.php) -- [docs/tools/composerRequireChecker.md](./tools/composerRequireChecker.md)

Ensures all code dependencies are explicitly declared in `composer.json`. Catches use of transitive dependencies that are not directly required.

This tool runs as a **PHAR** from `vendor-phar/composer-require-checker.phar`.

### Markdown Links Checker

[MarkdownLinksTool](../src/Pipeline/Lane/MarkdownLinksTool.php) -- [docs/tools/markdownLinks.md](./tools/markdownLinks.md)

Checks your `README.md` file and all `*.md` files in the `docs` directory. For each link found, it ensures the link target is valid -- both internal links to project files and external links to remote web pages.

### Twig Lint and Yaml Lint (Symfony only)

[TwigLintTool](../src/Pipeline/Lane/TwigLintTool.php) -- [docs/tools/twigLint.md](./tools/twigLint.md);
[YamlLintTool](../src/Pipeline/Lane/YamlLintTool.php) -- [docs/tools/yamlLint.md](./tools/yamlLint.md)

Platform lanes appended to this phase on a Symfony project: `bin/console lint:twig` and `bin/console lint:yaml` over the configured directories (`templates/` and `config/` by default; `withTwigDirectories()` / `withYamlDirectories()` in `qaConfig/qa.php`). Not `-t` selectable; skipped cleanly on a generic project.

## Phase 3: Static Analysis

### Branch Name Policy

[BranchNamePolicyTool](../src/Pipeline/Lane/BranchNamePolicyTool.php)

Always-on check that runs **first** in this phase. Enforces the PR branch-naming convention (a PR
branch must use an allowed prefix — `feature/`, `bugfix/`, `chore/`, `hotfix/` — never `plan/*`);
the repo's detected default branch is exempt. See [CLAUDE/branch-policy.md](./../CLAUDE/branch-policy.md).

### PHPStan ignoreErrors Justification

[PhpstanIgnoreJustificationTool](../src/Pipeline/Lane/PhpstanIgnoreJustificationTool.php)

Always-on check that every `ignoreErrors` entry in `qaConfig/phpstan.neon` carries a comment naming the hazard accepted and its scope. See [tools/phpstanIgnoreJustification.md](./tools/phpstanIgnoreJustification.md).

### PHPStan

[PhpstanTool](../src/Pipeline/Lane/PhpstanTool.php) -- [docs/tools/phpstan.md](./tools/phpstan.md)

Static analysis of your PHP code. Runs as a **PHAR** from `vendor-phar/phpstan.phar`.

The default configuration runs at `level: max`.

PHP-QA-CI bundles custom PHPStan rules (auto-loaded via extension installer) and ships with `phpstan-strict-rules` and `phpstan-phpunit` as Composer dependencies.

Please see the [PHPQA PHPStan docs](./tools/phpstan.md) for full details.

See the [PHPStan project page](https://github.com/phpstan/phpstan) for more information about PHPStan in general.

### Dead Code Detection

[DeadCodeTool](../src/Pipeline/Lane/DeadCodeTool.php) -- [docs/tools/deadCode.md](./tools/deadCode.md)

Opt-in: `withDeadCodeDetection(true)` plus `withDeadCodeEntryPoints(...)` (or
`withoutDeadCodeEntryPoints()`) in `qaConfig/qa.php`. Runs shipmonk/dead-code-detector through
`vendor-phar/phpstan.phar`, loaded from `vendor-phar/dead-code-detector.phar` rather than from any
Composer package, so the PHPStan gate never sees it. A member only tests reach is reported.

### PHPArkitect

[PhpArkitectTool](../src/Pipeline/Lane/PhpArkitectTool.php) -- [docs/tools/phpArkitect.md](./tools/phpArkitect.md)

Architecture rules (class naming, namespace layering, dependency direction). On by default; runs as
a **PHAR** from `vendor-phar/phparkitect.phar`. Disable per-project with `withArkitect(false)` in `qaConfig/qa.php`. See
the [PHPArkitect section in the README](./../README.md#phparkitect-architecture-rules).

### SensitiveParameter Usage

[SensitiveParameterUsageTool](../src/Pipeline/Lane/SensitiveParameterUsageTool.php)

Always-on security baseline: fails if the native `#[\SensitiveParameter]` attribute is used nowhere
in `src/`. Opt out per-project with `withSensitiveParameterCheck(false)` in `qaConfig/qa.php`. See
[tools/sensitiveParameterUsage.md](./tools/sensitiveParameterUsage.md).

## Phase 4: Testing

### PHPUnit

[PhpunitTool](../src/Pipeline/Lane/PhpunitTool.php) -- [docs/tools/phpunit.md](./tools/phpunit.md)

Runs your [PHPUnit](https://github.com/sebastianbergmann/phpunit) tests. PHPUnit is installed as a Composer dependency.

Please see the [PHPQA PHPUnit docs](./tools/phpunit.md) for full details.

### Infection

[InfectionTool](../src/Pipeline/Lane/InfectionTool.php) -- [docs/tools/infection.md](./tools/infection.md)

Mutation testing -- runs your PHPUnit tests but deliberately mutates your code in ways that should make your tests fail. If they don't, you "failed to kill the mutant".

Runs as a **PHAR** from `vendor-phar/infection.phar`. Requires Xdebug and code coverage enabled; on by default, `withInfection(false)` to disable.

Please see the [PHPQA Infection docs](./tools/infection.md) for full details.

## Post-Success

### PHPCPD

[PhpcpdTool](../src/Pipeline/Lane/PhpcpdTool.php) -- [docs/tools/phpcpd.md](./tools/phpcpd.md)

Reports duplicated code and writes a JSON report. Informational only -- cannot fail the pipeline.

Then the project's own `qaConfig/hookPost.php`, if present.
