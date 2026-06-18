# PHP-QA-CI

A comprehensive quality assurance and continuous integration pipeline for PHP 8.3+ projects, written in Bash. Runs tools in a logical order designed to fail as quickly as possible, suitable for both local development and CI.

This package is written for and tested on Linux.

## Install

```bash
composer require --dev lts/php-qa-ci:dev-php8.4@dev
```

The `qa` script will be installed in your project's bin directory. By default, Composer uses `vendor/bin`, but you can configure a custom location in your `composer.json`:

```json
"config": {
    "bin-dir": "bin"
}
```

For Symfony projects, you can accept the prompts to run recipes, but you will then need to decide whether to stick with Symfony defaults or the php-qa-ci defaults (which are more extensive). If you decide to keep the php-qa-ci defaults, remove the config files created by the Symfony recipe:

```bash
# Revert to php-qa-ci PHPUnit configs (compare files first)
rm phpunit.xml.dist
ln -s vendor/lts/php-qa-ci/configDefaults/generic/phpunit.xml
```

### Required Composer Configuration

Your project's `composer.json` must allow the required plugins:

```json
{
    "config": {
        "allow-plugins": {
            "ergebnis/composer-normalize": true,
            "lts/php-qa-ci": true,
            "phpstan/extension-installer": true
        }
    }
}
```

## Disabling Config Push

This project will push config updates direclty into the main repo

If this is not desired eg in production,staging,CI deployments then

```
export PHP_QA_CI_DISABLE_CONFIG_PUSH=true
```

## What It Does

PHP-QA-CI orchestrates multiple PHP quality tools across four phases:

**Phase 1 -- Code Modification:**

1. Rector (safe functions, PHPUnit, PHP 8.4 upgrades)
2. PHP CS Fixer

**Phase 2 -- Linting and Validation:**
3\. PSR-4 Validation
4\. Composer Checks
5\. Strict Types Enforcement
6\. PHP Lint
7\. Composer Require Checker
8\. Markdown Links Checker

**Phase 3 -- Static Analysis:**
9\. PHPStan (level max)
10\. PHPArkitect (architecture rules; on by default, `useArkitect=0` to disable)

**Phase 4 -- Testing:**
11\. PHPUnit
12\. Infection (mutation testing, optional, requires Xdebug)

**Post-Success:** PHPLoc (stats only, cannot fail)

See [Pipeline Architecture](./docs/pipeline.md) for full details.

## Tool Delivery

PHP-QA-CI uses a hybrid approach to tool delivery:

- **PHARs** (via [PHIVE](https://phar.io/)): PHPStan, PHP CS Fixer, Infection, Composer Require Checker, PHPArkitect (PHIVE key `D9C905CED1932CA2` — the trailing 16 chars of the full fingerprint `47CD54B6398FE21B3709D0A4D9C905CED1932CA2`, which is what `tool-install.bash` pins) -- delivered in `vendor-phar/`
- **Composer dependencies**: PHPUnit, phpstan-strict-rules, phpstan-phpunit, parallel-lint
- **Isolated Composer project**: Rector -- in `tools/rector/` with its own `composer.json` to prevent dependency conflicts

The `phpstan/phpstan` package is in the `replace` section of `composer.json` since PHPStan is provided via PHAR. This prevents version conflicts when consuming projects also require PHPStan extensions.

## PHPArkitect (architecture rules)

[PHPArkitect](https://github.com/phparkitect/arkitect) enforces *structural*
rules that PHPStan expresses awkwardly: class-naming conventions, namespace
layering, and dependency direction. It runs in Phase 3 and is **on by default**.

### Where does a rule belong — PHPArkitect or PHPStan?

**Default to PHPArkitect for structural rules. Upgrade to a PHPStan rule only when
you need finer-grained, method-level, or semantic detection that arkitect cannot
express.**

- **PHPArkitect (the default)** reasons about a class's *identity*: its kind
  (interface / enum / trait / class), its name, the namespace it sits in, and its
  ancestry. Reach for it for naming conventions, namespace layering, and
  dependency direction.
- **PHPStan (the upgrade)** reasons about *code*. Move up to a PHPStan rule only
  when the check needs something arkitect cannot see or say:
  - a **method-level** predicate — e.g. "the class has a public `__invoke`";
  - **"any of N name patterns, except an allow-list"** — arkitect's
    `HaveNameMatching` is a single glob with no OR / except composite;
  - a **type-kind carve-out** in a dependency rule — e.g. allow generated *enums*
    but forbid generated *objects*; `NotDependsOnTheseNamespaces` has no type-kind
    awareness;
  - any **behavioural / semantic** check — type bans, call-site shape,
    docblock-driven rules, loose comparison, nested ternary.

**Single Source of Truth — never enforce one convention in both engines.** Adding
arkitect is *not* purely additive: when a structural convention already lives in a
PHPStan rule, **migrate** it to arkitect (and delete the PHPStan rule) rather than
running both. Two engines enforcing one rule is a defect — duplicated failure
messages, drift between them, and double maintenance. (The shipped Interface / Enum /
Trait suffix convention was migrated exactly this way: it used to be the PHPStan
`RequireTypeSuffixRule` and is now owned solely by the default arkitect tier.)

Rules are organised in tiers (mirroring the `rules-default` / `rules-optional`
PHPStan neon split). php-qa-ci ships each as a file returning a list of arkitect
`ArchRule` objects, and the pipeline exports the resolved path of each so a
project config can compose them without knowing the vendor layout:

| Tier                                 | Env var                                   | Default               | Contents                                |
| ------------------------------------ | ----------------------------------------- | --------------------- | --------------------------------------- |
| `phparkitect-rules-default`          | `PHPQACI_ARKITECT_RULES_DEFAULT`          | **on**, every project | Interface / Enum / Trait name suffixes  |
| `phparkitect-rules-optional`         | `PHPQACI_ARKITECT_RULES_OPTIONAL`         | opt-in                | `*Exception` suffix, `Abstract*` prefix |
| `phparkitect-rules-optional-symfony` | `PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY` | opt-in                | `*Command`, `*Subscriber`               |

The default tier matches on AST node *kind*, so it never forces arkitect to
resolve class ancestry — that keeps it safe for any project. Ancestry-resolving
rules (`IsA`/`Extend`/`Implement`, e.g. the `*Exception` convention) need a
complete autoloader, so they live in the optional tier.

### Troubleshooting: optional/symfony tiers need a complete autoloader

The optional and symfony tiers use ancestry rules (`IsA`) that resolve a class's
parents by **reflecting** it — so the analysed classes must be autoloadable. The
pipeline runs arkitect with `--autoload=vendor/autoload.php`, so this is normally
fine. But if you opt into these tiers and your autoloader is incomplete, `IsA`
rules **silently match nothing** — arkitect reports "No violations" (a false
green) rather than failing. (A genuine crash — exit > 1 — instead means a broken
config or an unparseable file.) If an opted-in `*Exception`/`*Command`/`*Subscriber`
rule never seems to fire, run `composer dump-autoload` and confirm your classes
load.

**Project usage.** With no project config, the default tier is applied to the
detected source dir automatically. To go further, add `qaConfig/phparkitect.php`
(copy `templates/qaConfig-phparkitect.php`) where you can:

- **extend** the default tier (`require getenv('PHPQACI_ARKITECT_RULES_DEFAULT')`),
- **opt in** to the optional / symfony tiers (their env vars),
- **add** project-bespoke rules,
- **replace** a tier wholesale by dropping your own `qaConfig/phparkitect-rules-*.php` (resolved ahead of the shipped copy by `configPath`).

Disable arkitect for a project with `export useArkitect=0` in
`qaConfig/qaConfig.inc.bash`. Run it alone with `vendor/bin/qa -t arch`.

## Custom PHPStan Rules

### Always-on rules (auto-loaded)

These rules are active automatically in every project that uses php-qa-ci — no configuration needed:

- **ForbidMockingFinalClassRule** -- Prevents mocking of final classes
- **ForbidAllowMockWithoutExpectationsRule** -- Bans `#[AllowMockObjectsWithoutExpectations]`
- **ForbidDangerousFunctionsRule** -- Bans exec/eval/unserialize and similar
- **ForbidEmptyCatchBlockRule** -- Requires catch blocks to have a body
- **RequireDeclareStrictTypesRule** -- Requires `declare(strict_types=1)` in all PHP files
- **RequireSensitiveParameterAttributeRule** -- Requires `#[\SensitiveParameter]` on plaintext
  credential parameters (names matching `password`, `secret`, `privateKey`, … with a
  `string`/`?string`/untyped/`mixed` type). Object-typed params and already-hashed/encoded names
  (`$hashedPassword`, `$passwordHash`) are ignored. Keeps credentials out of stack traces.

> The codebase-wide "is `#[\SensitiveParameter]` used **anywhere**?" coverage check is NOT a PHPStan
> rule — PHPStan rules are opt-in (a consumer must include this library's rules neon), so they cannot
> be relied on estate-wide. That check ships as an always-on pipeline tool instead. See
> [SensitiveParameter usage check](#sensitiveparameter-usage-check-always-on).

#### Configuring RequireSensitiveParameterAttributeRule

The rule is wired in `rules-default.neon` from a `phpqaciSensitiveParameter` parameters block.
Override any key in your `qaConfig/phpstan.neon` (deep-merged over the defaults):

```neon
parameters:
    phpqaciSensitiveParameter:
        # Case-insensitive substrings that mark a parameter NAME as a credential.
        namePatterns:
            - password
            - passwd
            - pwd
            - passphrase
            - secret
            - apiSecret
            - privateKey
            - credential
            - credentials
        # Case-insensitive substrings that mark a name as already hashed/encoded
        # (and therefore NOT plaintext sensitive).
        ignoreSubstrings: [hash, hashed, encoded, encrypted]
```

### Optional rules (opt-in)

Ten additional rules ship as opt-in, split across two files:

- **`rules-optional.neon`** — 6 generic rules suitable for any PHP project
- **`rules-optional-symfony.neon`** — all generic rules + 4 Symfony/Doctrine-specific rules

To enable them, add an `includes` entry to your `qaConfig/phpstan.neon`.

**Symfony projects — include the full Symfony set** (automatically picks up new rules on upgrade):

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon
    - ../vendor/lts/php-qa-ci/rules-optional-symfony.neon
```

**Generic PHP projects — include the generic set only**:

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon
    - ../vendor/lts/php-qa-ci/rules-optional.neon
```

**Cherry-pick individual rules** (full control, manual updates required):

```neon
includes:
    - ../vendor/lts/php-qa-ci/configDefaults/generic/phpstan.neon

rules:
    - LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingEmptyStringRule
    - LTS\PHPQA\PHPStan\Rules\ForbidSilentCatchRule
    # ... add only what you want
```

See **[docs/tools/phpstan.md](docs/tools/phpstan.md)** for the full list of optional rules and descriptions.

## SensitiveParameter usage check (always-on)

Unlike the PHPStan rules above (which are opt-in), php-qa-ci ships an **always-on**
pipeline tool that asserts the native `#[\SensitiveParameter]` attribute is used at
least once in your project's `src/`. PHP 8.2+ redacts a so-marked argument from
stack traces, keeping passwords / tokens / secrets out of logs and error reporters.

The check runs automatically as part of `bin/qa` for **every** consumer — no neon
include required. The scan is AST-based, so the attribute is never false-matched in
strings or comments.

- **Run standalone**: `vendor/bin/qa -t sensitiveParameterUsage` (aliases: `spu`,
  `sensitiveparameter`).
- **Passes** when ≥1 `#[\SensitiveParameter]` is found; **fails** (exit 1) when none
  is found.

**Escape hatch** (opt-out, on by default) — for projects that genuinely never handle
a sensitive parameter (e.g. pure tooling libraries). Add to `qaConfig/qaConfig.inc.bash`:

```bash
export useSensitiveParameterCheck=0
```

php-qa-ci itself is the canonical example: it handles no secrets, so it sets this flag
in its own `qaConfig/qaConfig.inc.bash`.

> **Estate-wide impact**: because this is always on, every consumer's `bin/qa` now
> requires either at least one `#[\SensitiveParameter]` annotation or the opt-out flag
> above. Most projects should add the annotation rather than opt out.

Full details: **[docs/tools/sensitiveParameterUsage.md](docs/tools/sensitiveParameterUsage.md)**.

## Quick Setup Scripts

### GitHub Actions Setup

Automatically install the GitHub Actions workflow for continuous integration:

```bash
vendor/lts/php-qa-ci/scripts/install-github-actions.bash
```

This will:

- Create `.github/workflows/qa.yml` with an optimized QA pipeline
- Auto-detect your PHP version from `composer.json`
- Configure smart caching for faster builds
- Set up artifact storage for test results

### Branch Protection Setup

Configure GitHub branch protection rules with sensible defaults:

```bash
# Standard protection (admins can bypass)
vendor/lts/php-qa-ci/scripts/setup-branch-protection.bash

# Hardened protection (CI enforced for everyone)
vendor/lts/php-qa-ci/scripts/setup-branch-protection.bash --harden
```

**Prerequisites**: Requires [GitHub CLI](https://cli.github.com/) (`gh`) installed and authenticated.

## CI/CD Workflows

PHP-QA-CI includes three GitHub Actions workflows in `.github/workflows/`:

- **`ci.yml`** -- Runs on push/PR to `php8.4`, executes `bash ci.bash`
- **`qa.yml`** -- Template workflow for consuming projects (copy to your project)
- **`update-deps.yml`** -- Weekly scheduled workflow that updates all dependencies (Composer, PHARs via PHIVE, isolated Rector), runs the full QA pipeline, and creates an auto-merge PR if green

See [GitHub Actions Integration](./docs/github-actions.md) for setup details.

## Claude Code Integration

PHP-QA-CI integrates with [Claude Code](https://docs.anthropic.com/en/docs/claude-code) to provide development guardrails and automation.

### Deployment

Deploy skills and hooks to your project:

```bash
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

This will:

- Copy hooks to `.claude/hooks/`
- Register them in `.claude/settings.json`
- Detect and configure hooks-daemon if present (see hooks-daemon documentation for installation)
- Migrate from legacy classic hooks if found

### Included Hooks

- `php-qa-ci__auto-continue.py` -- Reduces confirmation prompts
- `php-qa-ci__prevent-destructive-git.py` -- Blocks commands that destroy uncommitted changes
- `php-qa-ci__discourage-git-stash.py` -- Discourages git stash with escape hatch
- `php-qa-ci__block-plan-time-estimates.py` -- Prevents time estimates in plan documents
- `php-qa-ci__validate-claude-readme-content.py` -- Ensures docs contain instructions, not logs
- `php-qa-ci__enforce-markdown-organization.py` -- Enforces doc organization

See `.claude/hooks/README.md` for detailed hook documentation after deployment.

### Disabling Auto-Deployment (Dev / Staging / CI Hosts)

Skills, agents and hooks are deployed automatically on every `composer install`
and `composer update` via the `SkillsDeployPlugin`. This is intentional --
keeping `.claude/` config consistent across projects is a core goal.

On hosts where this is unwanted (dev / staging deploys, build images, CI runners
that aren't Claude Code environments) the deployment can leave the working tree
dirty. Opt out by exporting:

```bash
export PHP_QA_CI_DISABLE_CONFIG_PUSH=true
```

When set (any truthy value -- `true`, `1`, `yes`, `on`), the plugin logs that it
was disabled and exits without touching `.claude/`. When unset (the default), the
plugin logs the opt-out instructions every time it runs so deploy operators can
discover the flag.

### Composer Plugins

PHP-QA-CI registers three Composer plugins:

- **PhiveUpdatePlugin** -- Manages PHAR installation via PHIVE
- **SkillsDeployPlugin** -- Deploys Claude Code skills and hooks
- **PhpStanGuardPlugin** -- Prevents `phpstan/phpstan` from being installed alongside the PHAR

## Docs

Comprehensive documentation is available in the [./docs](./docs) folder:

- **[Pipeline Architecture](./docs/pipeline.md)** -- Tool execution order and phases
- **[Tools Overview](./docs/phpqa-tools.md)** -- All tools with configuration details
- **[Configuration](./docs/configuration.md)** -- Customizing tool settings and overrides
- **[Coding Standards](./docs/coding-standards.md)** -- PHP CS Fixer and Rector configuration
- **[GitHub Actions Integration](./docs/github-actions.md)** -- CI/CD setup guide
- **[Continuous Integration](./docs/ci.md)** -- General CI usage and workflows
- **[Platform Detection](./docs/platform-detection.md)** -- Symfony/Laravel specific settings

Tool-specific documentation:

- **[PHPStan](./docs/tools/phpstan.md)** -- Static analysis configuration and custom rules
- **[PHPUnit](./docs/tools/phpunit.md)** -- Test runner configuration and modes
- **[Infection](./docs/tools/infection.md)** -- Mutation testing setup

## Other Notes

### Specify PHP Binary Path

If you are running multiple PHP versions, you can specify which one to use:

```bash
export PHP_QA_CI_PHP_EXECUTABLE=/bin/php84
vendor/bin/qa

# Or inline:
PHP_QA_CI_PHP_EXECUTABLE=/bin/php84 vendor/bin/qa
```

### Running Specific Tools

```bash
# Run only PHPStan
vendor/bin/qa -t stan

# Run only PHP CS Fixer
vendor/bin/qa -t fixer

# Run on specific path
vendor/bin/qa -t stan -p src/Domain
```

### Branches

- `php8.4` -- Default branch, targets PHP 8.4
- `php8.3` -- PHP 8.3 support

## Long Term Support

This package was brought to you by Long Term Support LTD, a company run and founded by Joseph Edmonds.

You can get in touch with Joseph at https://ltscommerce.dev/

Check out Joseph's recent book [The Art of Modern PHP 8](https://ltscommerce.dev/#book)
