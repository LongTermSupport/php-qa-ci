# PHP-QA-CI

## Install

The qa script will be installed in your project's bin directory. By default, Composer uses `vendor/bin`, but you can configure a custom location in your composer.json:

```
  "config": {
    "bin-dir": "bin",  // Optional: changes from vendor/bin to bin
```

Then install the current bleeding edge, run

```
composer require --dev lts/php-qa-ci:dev-master@dev
```

For Symfony - you can accept the prompts to run recipes, but you will then need to decide to either stick with Symfony defaults or the php-qa-ci default which are more extensive

If you decide to stick with the lts defaults, then you should remove the config files that the symfony recipe created. If you want to keep config files in your root directory, you can choose to symlink them to the php-qa-ci files, eg

Note that you should properly compare the files before doing this.

```
# revert to php-qa-ci PHPUnit configs
rm phpunit.xml.dist
ln -s vendor/lts/php-qa-ci/configDefaults/generic/phpunit.xml 
```



## Introduction

PHP-QA-CI is a quality assurance and continuous integration pipeline written in BASH that can be run both on the desktop
as part of your development process and then also as part of a continuous integration (CI) pipeline.

It runs tools in a logical order and will fail as quickly as possible.

This package is written for and has only been tested on Linux.

## Quick Setup Scripts

PHP-QA-CI includes convenient scripts for setting up continuous integration and branch protection:

### GitHub Actions Setup

Automatically install GitHub Actions workflow for continuous integration:

```bash
# Run from your project root
vendor/lts/php-qa-ci/scripts/install-github-actions.bash
```

This script will:
- Create `.github/workflows/qa.yml` with optimized QA pipeline
- Auto-detect your PHP version from `composer.json`
- Configure smart caching for faster builds
- Set up artifact storage for test results

After installation, the QA pipeline will run automatically on all pushes and pull requests.

### Branch Protection Setup

Configure GitHub branch protection rules with sensible defaults:

```bash
# Standard protection (admins can bypass)
vendor/lts/php-qa-ci/scripts/setup-branch-protection.bash

# Hardened protection (CI enforced for everyone)
vendor/lts/php-qa-ci/scripts/setup-branch-protection.bash --harden
```

The script configures:
- Required CI checks (PHP QA Pipeline must pass)
- PR review requirements
- Protection against force pushes and deletions
- Auto-delete merged branches
- Conversation resolution requirements

**Prerequisites**: Requires [GitHub CLI](https://cli.github.com/) (`gh`) installed and authenticated.

## Claude Code Integration

PHP-QA-CI now integrates seamlessly with [Claude Code Hooks Daemon](https://docs.anthropic.com/en/docs/claude-code/hooks) to provide enhanced development guardrails and automation when using Claude Code.

### What is hooks-daemon?

hooks-daemon is a high-performance daemon for Claude Code hooks that provides 20x faster execution than classic hooks after warmup. It enables intelligent workflow enforcement, destructive command prevention, and automated quality checks.

### Integration Features

When you deploy php-qa-ci skills and hooks to your project, the deployment script automatically:

- **Detects hooks-daemon** - Checks if `.claude/hooks-daemon.yaml` exists
- **Configures required handlers** - Ensures the daemon has the necessary handlers enabled with correct settings
- **Migrates from classic hooks** - Removes legacy `.claude/hooks/*.py` files and settings.json registrations
- **Provides clear instructions** - If daemon not detected, displays installation guide

### Benefits

✅ **No double execution overhead** - Single handler execution instead of running both classic hooks and daemon handlers
✅ **Superior performance** - 20x faster after warmup via Unix socket IPC
✅ **Better implementations** - Daemon handlers include enhancements and bug fixes
✅ **Single source of truth** - Daemon provides all hook functionality
✅ **Automatic configuration** - php-qa-ci enforces required daemon settings

### Required Daemon Handlers

When hooks-daemon is detected, php-qa-ci configures these handlers:

- `git_stash` (mode: deny) - Strict blocking of git stash operations
- `plan_time_estimates` - Prevents time estimates in plan documents
- `validate_instruction_content` - Ensures docs contain instructions, not logs
- `markdown_organization` - Enforces documentation organization

### Installation

**Deploy skills and hooks** (will configure daemon if present):
```bash
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

**Install hooks-daemon** (if not already installed):
```bash
git clone -b v2.2.0 https://docs.anthropic.com/en/docs/claude-code/hooks.git .claude/hooks-daemon
cd .claude/hooks-daemon
./scripts/install/install.bash
```

**Documentation**: See `.claude/hooks/README.md` for detailed hook documentation and [hooks-daemon repository](https://docs.anthropic.com/en/docs/claude-code/hooks) for daemon documentation.

## Docs

Comprehensive documentation is available in the [./docs](./docs) folder:

- **[GitHub Actions Integration](./docs/github-actions.md)** - Complete CI/CD setup guide with customization options
- **[Pipeline Architecture](./docs/pipeline.md)** - Detailed tool execution order and phases
- **[Configuration](./docs/configuration.md)** - Customizing tool settings and overrides
- **[Coding Standards](./docs/coding-standards.md)** - PHP CS Fixer and Rector configuration
- **[Platform Detection](./docs/platform-detection.md)** - Symfony/Laravel specific settings

## Other notes

### Specify PHP Binary Path

if you are running multiple PHP versions, you can specify which one to use like so:

```bash
# If using default vendor/bin location:
export PHP_QA_CI_PHP_EXECUTABLE=/bin/php81
vendor/bin/qa

# Or inline:
PHP_QA_CI_PHP_EXECUTABLE=/bin/php81 vendor/bin/qa

# If you configured "bin-dir": "bin" in composer.json:
PHP_QA_CI_PHP_EXECUTABLE=/bin/php81 bin/qa
```


## Long Term Support

This package was brought to you by Long Term Support LTD, a company run and founded by Joseph Edmonds

You can get in touch with Joseph at https://ltscommerce.dev/

Check out Joseph's recent book [The Art of Modern PHP 8](https://ltscommerce.dev/#book)



