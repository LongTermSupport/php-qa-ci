# PHPQA Continuous Integration

PHPQA is very well suited to running as part of a CI pipeline.

All that is really required is to export the `CI` environment variable, though you probably also want to ensure that full tests are run.

For example, have a look at the [ci.bash](./../ci.bash) script that PHPQA's dependency update uses to verify itself.

The usage of a generic `ci.bash` script is highly encouraged. Specific CI configuration can then be handled elsewhere, but that CI system should ultimately run the `ci.bash` script to perform the actual checks. This helps you avoid becoming overly coupled to a specific CI platform.

## GitHub Actions Workflows

PHP-QA-CI runs three GitHub Actions workflows from `.github/workflows/`; the third, `release.yml`, is
described in [GitHub Actions Integration](./github-actions.md#release-automation):

### qa.yml

The one QA workflow. It runs the whole pipeline once for each push to a pull request, and once for a
push to the default branch (`php8.5`), where its result gates a release. A push to a work branch
starts no run of its own: its pull request runs. Its job is named `QA Pipeline`, the status check
the default branch requires, and the name does not change with the PHP line.

It is also the **template workflow** for consuming projects, byte for byte: the `versionPins` lane
fails when it differs from [templates/github-actions/php-qa-ci.yml](./../templates/github-actions/php-qa-ci.yml),
so every pull request here proves the file consumers copy. Copy it to your project's
`.github/workflows/qa.yml` to get a fully configured QA pipeline with:

- One run per pull request push; a newer push cancels the run it supersedes
- A read-only token (`permissions: contents: read`)
- PHP version detection from `composer.json`, as a step of the one job
- Smart caching for Composer and the QA tool cache
- Manual tool selection via `workflow_dispatch`
- Artifact storage for test results
- An opt-in coverage comment on pull requests (repository variable `PHP_QA_CI_COVERAGE_REPORT`)

The template lists the branches a push runs for (`main`, `master`, `php8.5`): put your default branch
there if it is not one of them. Commit fixes back to a pull request with the `qa-autofix.yml`
template instead.

See the [GitHub Actions Integration](./github-actions.md) guide for full setup instructions.

### update-deps.yml

A **daily scheduled workflow** that automatically:

1. Updates Composer dependencies (`composer update`)
2. Updates PHARs via PHIVE (`scripts/tool-install.bash update`)
3. Updates the self-built PHARs (`composer update --working-dir=build/<tool>` for each manifest, then `scripts/build-phar.bash --all --force`)
4. Records each moved tool in `CHANGELOG.md` (`bin/changelog-release add-tool-updates`)
5. Runs the full QA pipeline on a work branch, judged from the merge base as the pull request will be
6. Creates or updates a pull request with the changes (if any), and approves the pull request's held `qa.yml` run
7. On failure, comments on the open `update-deps-failure` issue, or opens one

Steps 1 to 5 run in a job whose token only reads. The change they produce travels as a patch to a
separate job that holds the write permissions and does nothing but apply it and open the pull
request, so no dependency the update installs runs beside a token that can push.

The pull request is not auto-merged: someone reads it and merges it. The workflow can also be triggered manually via `workflow_dispatch`.

See [.github/workflows/update-deps.yml](./../.github/workflows/update-deps.yml).
