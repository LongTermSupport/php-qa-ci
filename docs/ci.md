# PHPQA Continuous Integration

PHPQA is very well suited to running as part of a CI pipeline.

All that is really required is to export the `CI` environment variable, though you probably also want to ensure that full tests are run.

For example, have a look at the [ci.bash](./../ci.bash) script that PHPQA uses to test itself in CI.

The usage of a generic `ci.bash` script is highly encouraged. Specific CI configuration can then be handled elsewhere, but that CI system should ultimately run the `ci.bash` script to perform the actual checks. This helps you avoid becoming overly coupled to a specific CI platform.

## GitHub Actions Workflows

PHP-QA-CI ships with three GitHub Actions workflows in `.github/workflows/`:

### ci.yml

The main CI workflow. Runs on push and pull requests to the `php8.4` branch. Executes `bash ci.bash`.

See [.github/workflows/ci.yml](./../.github/workflows/ci.yml).

### qa.yml

A **template workflow** for consuming projects. Copy this to your project's `.github/workflows/qa.yml` to get a fully configured QA pipeline with:

- Dynamic PHP version detection from `composer.json`
- Smart caching for Composer, PHARs, and PHPStan cache
- Auto-commit capability for Rector/CS Fixer changes (on feature branches only)
- Manual tool selection via `workflow_dispatch`
- Artifact storage for test results

See the [GitHub Actions Integration](./github-actions.md) guide for full setup instructions.

### update-deps.yml

A **weekly scheduled workflow** that automatically:

1. Updates Composer dependencies (`composer update`)
2. Updates PHARs via PHIVE (`phive update`)
3. Updates the isolated Rector installation (`composer update --working-dir=tools/rector`)
4. Runs the full QA pipeline to verify everything still passes
5. Creates a pull request with the changes (if any)
6. Enables auto-merge on the PR

This ensures dependencies stay current without manual intervention. The workflow can also be triggered manually via `workflow_dispatch`.

See [.github/workflows/update-deps.yml](./../.github/workflows/update-deps.yml).
