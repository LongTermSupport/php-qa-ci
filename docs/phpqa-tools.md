# PHPQA Tools

QA runs a suite of standard tools contained in the `includes/generic` folder. These are run from the `bin/qa` script via the `runTool` function.

The tools are run in a specific order across four phases, designed to modify code first (Phase 1), then validate (Phase 2), analyse (Phase 3), and test (Phase 4). The pipeline fails as quickly as possible.

In local development, a failed tool can be retried indefinitely. In CI, a failed tool fails the whole process.

## The Tool Runner

Each tool is run by calling the [`runTool`](./../includes/functions.inc.bash#L20) function.

The `runTool` function takes into account the platform that PHPQA detected via the [`detectPlatform`](./../includes/functions.inc.bash#L6) function.

You can override any tool for your project by copying it into `qaConfig/tools` and editing as you see fit.

- It will first check for project level overrides in `/project/root/qaConfig/tools/{toolName}.inc.bash`
- Then it will look inside `includes/{detectedPlatform}/{toolName}.inc.bash`
- If none is found, it will fall back to `includes/generic/{toolName}.inc.bash`

The platform-specific script will be run instead of the generic script. You can choose to `source` the generic tool in your script:

```bash
source $DIR/../includes/generic/setConfig.inc.bash
... platform-specific script contents ...
```

## Phase 1: Code Modification

These tools can modify your source files.

### Rector

[Rector Tool](../includes/generic/rector.inc.bash)

Rector performs automated refactoring and code upgrades. It is delivered as a committed, self-contained PHAR (`vendor-phar/rector.phar`) built by `scripts/build-rector-phar.bash`. The PHAR bundles its own *extracted* `phpstan/phpstan`, so Rector's PHPStan dependency never leaks into any consuming project's dependencies (and, being its own process, never collides with the pipeline's `phpstan.phar`).

The pipeline runs Rector in three stages:

1. **Safe Functions** -- Converts standard PHP functions to their [thecodingmachine/safe](https://github.com/thecodingmachine/safe) equivalents (which throw exceptions instead of returning false). Requires `thecodingmachine/safe` as a production dependency.
2. **PHPUnit** -- Applies PHPUnit-specific rector rules to the test directory.
3. **PHP 8.5** -- Applies PHP 8.5 migration rules (skipped if a project-specific `rector.php` or `qaConfig/rector.php` is found, as it is assumed those handle version upgrades).

Default configurations:
- [rector-safe.php](../configDefaults/generic/rector-safe.php)
- [rector-phpunit.php](../configDefaults/generic/rector-phpunit.php)
- [rector-php85.php](../configDefaults/generic/rector-php85.php)

### PHP CS Fixer

[PHP CS Fixer Tool](../includes/generic/phpCsFixer.inc.bash)

PHP CS Fixer automatically fixes code style issues according to modern PHP standards. It runs as a **PHAR** from `vendor-phar/php-cs-fixer.phar` (not as a Composer dependency).

The default configuration includes `@PHP8x5Migration` rules for PHP 8.5 compatibility, including nullable type declarations.

Please see the [PHPQA Coding Standards docs](./coding-standards.md) for configuration details.

See the [PHP CS Fixer Docs](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) for more information.

## Phase 2: Linting and Validation

These tools validate code without modifying it.

### PSR-4 Validation

[PSR-4 Validation Tool](../includes/generic/psr4Validate.inc.bash)

Checks for code whose namespaces do not comply with the PSR-4 standard.

#### Ignore List

You can specify files or directories to be ignored by the validator. This is a newline-separated list of valid regex including a valid regex delimiter. For example:

```
#tests/bootstrap\.php#
#tests/phpstan-bootstrap\.php#
#tests/assets/.*?asset#
```

### Composer Checks

[Composer Checks Tool](../includes/generic/composerChecks.inc.bash)

- Checks that `ergebnis/composer-normalize` plugin is allowed
- Runs `composer diagnose` to check for issues
- Runs `composer normalize` to normalize `composer.json`
- Dumps the autoloader to ensure recent code changes will not cause autoloading issues

### Package Type Declaration

[Package Type Tool](../includes/generic/packageType.inc.bash)

Always-on check that requires `composer.json` to declare an explicit `type`. Runs immediately
after Composer Checks. See [tools/packageType.md](./tools/packageType.md) for details.

### Config Template Ignore-List Audit

[Config Template Ignore-List Tool](../includes/generic/configTemplateIgnoreList.inc.bash)

Always-on self-check that php-qa-ci runs against its own shipped `configDefaults/generic/`: every
namespace-less config template a consumer is documented to copy into `qaConfig/` must be matched
by a pattern in `psr4-validate-ignore-list.txt`, or the documented override fails `psr4Validate`.
Identifier `phpqaci.configTemplateIgnoreList`. See
[tools/configTemplateIgnoreListCheck.md](./tools/configTemplateIgnoreListCheck.md).

### Infection Config Source Directories Check

[Infection Config Source Directories Tool](../includes/generic/infectionConfigSourceDirs.inc.bash)

Always-on check that every entry in infection.json's `source.directories` resolves, relative to
infection.json's own directory, to a real directory. Identifier `phpqaci.infectionConfigSourceDirs`.
See [tools/infectionConfigSourceDirs.md](./tools/infectionConfigSourceDirs.md).

### PHPUnit Config Version Check

[PHPUnit Config Version Tool](../includes/generic/phpunitConfigVersion.inc.bash)

Always-on check that the resolved phpunit.xml's version pins (the schema URL in
`xsi:noNamespaceSchemaLocation` and any `SYMFONY_PHPUNIT_VERSION` pin) match the major version of
the installed PHPUnit. Runs immediately after the Infection Config Source Directories Check;
standalone alias `-t pcv`. Identifier `phpqaci.phpunitConfigVersion`. See
[tools/phpunitConfigVersion.md](./tools/phpunitConfigVersion.md).

### GitHub Actions PHP Version Check

[GitHub Actions PHP Version Tool](../includes/generic/githubActionsPhpVersion.inc.bash)

Always-on check that every GitHub Actions workflow under `.github/workflows/` and every shipped
template under `templates/github-actions/` that derives a runner PHP from `composer.json` can
select, and defaults to, the PHP version `composer.json` requires; the shipped consumer template
must stay identical to the workflow this repository runs. Runs immediately after the PHPUnit
Config Version Check; standalone alias `-t gapv`. Identifier `phpqaci.githubActionsPhpVersion`.
See [tools/githubActionsPhpVersion.md](./tools/githubActionsPhpVersion.md).

### Strict Types Enforcement

[Strict Types Tool](../includes/generic/phpStrictTypes.inc.bash)

Scans `.php` and `.phtml` files under the checked paths for a missing `declare(strict_types=1)`.
On a read-only run it reports every offending file and fails; on a writable run it adds the
declaration to the opening `<?php` tag automatically (a file with no opening tag fails the gate).
There is no interactive prompt.

### PHP Parallel Lint

[PHP Lint Tool](../includes/generic/phpLint.inc.bash)

Very fast PHP linting process. Checks for syntax errors in your PHP files.

See the [PHP Parallel Lint project page](https://github.com/php-parallel-lint/PHP-Parallel-Lint) for more information.

### Composer Require Checker

[Composer Require Checker Tool](../includes/generic/composerRequireChecker.inc.bash)

Ensures all code dependencies are explicitly declared in `composer.json`. Catches use of transitive dependencies that are not directly required.

This tool runs as a **PHAR** from `vendor-phar/composer-require-checker.phar`.

#### Safe scan-files preflight

The lane starts with an always-on preflight: every `thecodingmachine/safe` entry in the resolved
`composerRequireChecker.json`'s `scan-files` must name the generated file safe actually loads on
the PHP version QA runs under. Identifier `phpqaci.composerRequireCheckerSafeScanFiles`. See
[tools/composerRequireCheckerSafeScanFiles.md](./tools/composerRequireCheckerSafeScanFiles.md).

### Markdown Links Checker

[Markdown Links Checker Tool](../includes/generic/markdownLinks.inc.bash)

Checks your `README.md` file and all `*.md` files in the `docs` directory. For each link found, it ensures the link target is valid -- both internal links to project files and external links to remote web pages.

## Phase 3: Static Analysis

### Branch Name Policy

[Branch Name Policy Tool](../includes/generic/branchNamePolicy.inc.bash)

Always-on check that runs **first** in this phase. Enforces the PR branch-naming convention (a PR
branch must use an allowed prefix — `feature/`, `bugfix/`, `chore/`, `hotfix/` — never `plan/*`);
the repo's detected default branch is exempt. See [CLAUDE/branch-policy.md](./../CLAUDE/branch-policy.md).

### PHPStan

[PHPStan Tool](../includes/generic/phpstan.inc.bash)

Static analysis of your PHP code. Runs as a **PHAR** from `vendor-phar/phpstan.phar`.

The default configuration runs at `level: max`.

PHP-QA-CI bundles custom PHPStan rules (auto-loaded via extension installer) and ships with `phpstan-strict-rules` and `phpstan-phpunit` as Composer dependencies.

Please see the [PHPQA PHPStan docs](./tools/phpstan.md) for full details.

See the [PHPStan project page](https://github.com/phpstan/phpstan) for more information about PHPStan in general.

### PHPArkitect

[PHPArkitect Tool](../includes/generic/phpArkitect.inc.bash)

Architecture rules (class naming, namespace layering, dependency direction). On by default; runs as
a **PHAR** from `vendor-phar/phparkitect.phar`. Disable per-project with `export useArkitect=0`. See
the [PHPArkitect section in the README](./../README.md#phparkitect-architecture-rules).

### SensitiveParameter Usage

[SensitiveParameter Usage Tool](../includes/generic/sensitiveParameterUsage.inc.bash)

Always-on security baseline: fails if the native `#[\SensitiveParameter]` attribute is used nowhere
in `src/`. Opt out per-project with `export useSensitiveParameterCheck=0`. See
[tools/sensitiveParameterUsage.md](./tools/sensitiveParameterUsage.md).

## Phase 4: Testing

### PHPUnit

[PHPUnit Tool](../includes/generic/phpunit.inc.bash)

Runs your [PHPUnit](https://github.com/sebastianbergmann/phpunit) tests. PHPUnit is installed as a Composer dependency.

Please see the [PHPQA PHPUnit docs](./tools/phpunit.md) for full details.

### Infection

[Infection Tool](./../includes/generic/infection.inc.bash)

Mutation testing -- runs your PHPUnit tests but deliberately mutates your code in ways that should make your tests fail. If they don't, you "failed to kill the mutant".

Runs as a **PHAR** from `vendor-phar/infection.phar`. Requires Xdebug and code coverage enabled (`useInfection=1`).

Please see the [PHPQA Infection docs](./tools/infection.md) for full details.

## Post-Success

### PHPLoc

[PHPLoc Tool](../includes/generic/phploc.inc.bash)

Generates code statistics (lines of code, complexity). Informational only -- cannot fail the pipeline.
