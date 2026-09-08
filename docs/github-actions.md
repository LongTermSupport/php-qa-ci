# GitHub Actions Integration

## Quick Start

### Installation

```bash
# Run from your project root
vendor/lts/php-qa-ci/scripts/install-github-actions.bash
```

Or manually:

```bash
mkdir -p .github/workflows
cp vendor/lts/php-qa-ci/templates/github-actions/php-qa-ci.yml .github/workflows/qa.yml
```

### Commit and Push

```bash
git add .github/workflows/qa.yml
git commit -m "Add PHP QA pipeline"
git push
```

The workflow will run automatically on your next push or pull request.

## Features

### Dynamic PHP Version Detection

The workflow automatically detects the PHP version from your `composer.json` file's `require.php` constraint. No manual configuration needed.

### Smart Git Operations

- **Shallow clone by default**: Faster CI runs
- **Full clone when needed**: Only when `AUTO_COMMIT_FIXES` is enabled
- **Protected branch safety**: Never auto-commits to main/master or default branch

### Tool-Specific Runs

Manually trigger specific tools from GitHub UI:

- Actions -> PHP QA Pipeline -> Run workflow -> Select tool

### Artifact Storage

- Test results saved for 7 days
- Coverage reports available for download
- Useful for debugging failed builds

## Configuration

### Environment Variables

Add these to the `env:` section of your workflow:

```yaml
env:
  CI: true                          # Required
  AUTO_COMMIT_FIXES: 'false'       # Enable auto-commit of fixes
  phpUnitCoverage: 0                # Disable coverage
  phpqaQuickTests: 1                # Quick test mode
  useInfection: 0                   # Skip mutation testing
  phpqaMemoryLimit: 4G              # Memory limit for all QA tools
```

### Auto-Commit Fixes

To enable automatic committing of Rector/CS Fixer changes:

```yaml
env:
  AUTO_COMMIT_FIXES: 'true'  # Only on feature branches
```

**Safety Features:**

- Never commits to main/master branches
- Never commits to repository's default branch
- Requires write permissions in repository settings
- Adds `[skip ci]` to prevent infinite loops

### GitHub Repository Settings

#### Required Permissions

1. Go to Settings -> Actions -> General
2. Under "Workflow permissions":
   - Select "Read and write permissions"
   - Check "Allow GitHub Actions to create and approve pull requests" (if using auto-commits)

#### Branch Protection (Recommended)

1. Go to Settings -> Branches
2. Add rule for main/master branch
3. Check "Require status checks to pass before merging"
4. Select the required **check-run** names — these are the job names, not the workflow name.
   For the `php-qa-ci.yml` template they are "Detect PHP Version", "PHP QA (<version>)" (the version
   is filled in dynamically, e.g. "PHP QA (8.5)"), and "Coverage Report". ("PHP QA Pipeline" is the
   workflow name shown in the Actions tab, not a selectable status check.)

## Inline-Barrier (autofix → read-only gate)

Template: `templates/github-actions/qa-autofix.yml`

Two jobs daisy-chained with `needs:`. The autofix job writes and commits fixes back on PRs;
the gate job validates the fixed tree read-only in the **same run**:

```text
autofix (PR only: WRITE fixers + commit back) ──needs──► gate (READ-ONLY qa)
```

1. **autofix** runs only on `pull_request`. It checks out the PR branch **tip**, applies the
   fixers in WRITE mode (`QA_READONLY=0` → Rector + PHP CS Fixer rewrite), and if anything
   changed commits + pushes it back to the PR branch. It is **skipped** on push to the default
   branch (already clean, arriving from an auto-fixed PR).
2. **gate** (`needs: autofix`) checks out the same (possibly auto-fixed) tip and runs the
   pipeline **read-only**. On GitHub Actions php-qa-ci auto-enables read-only
   (`detectReadOnly`), so the fixers run as `--dry-run` and **fail on any pending change**;
   PHPStan etc. run as normal.

### Why no re-trigger / no PAT is needed

The gate validates the fixed tip **in the same run**. The autofix push uses the default
`GITHUB_TOKEN`, which deliberately starts **no** new workflow run — so there is no loop, no
cancellation, and nothing to wait for. (A re-trigger-based design would need a PAT; this one
does not.)

> Contrast with the `php-qa-ci.yml` template's `AUTO_COMMIT_FIXES`, which commits fixes at the
> **end** of a single run (so the validating tools ran against *unfixed* code). Here the fixers
> run **before** the gate, and the gate re-validates the fixed tree read-only.

### Default branch is dynamic (no hardcoded name)

`on:` filters cannot use expressions, so the template triggers on **all** pushes and **guards
the gate** to run — for push events — only on the default branch via
`github.ref_name == github.event.repository.default_branch`. PRs always run. So: PR →
autofix+gate; push to default (post-merge) → gate; push to a feature branch → skipped (its PR
covers it). No double-runs and no branch name baked into the file, which is what makes it safe
to copy verbatim into any repo. (A feature-branch push with no open PR produces an empty
skipped run — cosmetic.)

### Safety

- The autofix write-back runs **only on PRs**, never on push to the default branch — automated
  commits to production branches are never made.
- Requires `permissions: contents: write` on the autofix job (the workflow is `contents: read`
  by default) and Settings → Actions → General → "Read and write permissions".
- A custom composer `bin-dir` (e.g. `bin`) changes the qa path from `vendor/bin/qa` to
  `bin/qa` — replace every `vendor/bin/qa` `run:` line accordingly. This is the one edit the
  template cannot make for you.
- The gate runs `qa -t allCS` + `qa -t allStatic` (tests deferred — a PHPUnit suite usually
  needs a DB / services not on the runner). When your suite runs without external services,
  add a tests step plus a `gate.services` block.
- **Private first-party deps**: the template ships a guarded "Install SSH deploy keys" step
  that is a no-op unless the `CI_SSH_DEPLOY_BUNDLE` secret is set. If `composer.lock` pins any
  dep to a `github_deploy_*` SSH alias (CI's `GITHUB_TOKEN` can't clone private sibling repos),
  provision the existing **read-only** deploy keys once, from a dev container that holds them:
  `vendor/lts/php-qa-ci/scripts/ci-push-ssh-deploy-bundle.bash` (auto-discovers the aliases from
  `composer.lock`, bundles the keys, sets the secret). `lts/php-qa-ci` itself is public — no key.
- Pre-wired: `PHP_QA_CI_DISABLE_CONFIG_PUSH: 'true'` (workflow env, stops the composer plugins
  failing the read-only gate on config-push drift) and `composer install --no-scripts`.

### Install

```bash
mkdir -p .github/workflows
cp vendor/lts/php-qa-ci/templates/github-actions/qa-autofix.yml .github/workflows/qa.yml
```

## Automated Dependency Updates

PHP-QA-CI includes an `update-deps.yml` workflow that runs weekly to automatically update all dependencies:

- Composer dependencies (`composer update`)
- PHARs via PHIVE (`phive update`) -- PHPStan, PHP CS Fixer, Infection, Composer Require Checker, PHPArkitect
- Rector PHAR rebuild (`composer update --working-dir=build/rector-phar` then `scripts/build-rector-phar.bash --force`)

If changes are detected, it runs the full QA pipeline. If QA passes, it creates a pull request with auto-merge enabled.

To add this to your project:

```bash
cp vendor/lts/php-qa-ci/.github/workflows/update-deps.yml .github/workflows/update-deps.yml
```

The workflow checks out your repository's own default branch (it pins no
explicit `ref:`), so the copied file works as-is — no branch edit is required.

See [Continuous Integration](./ci.md) for more details on the available workflows.

## Customization

### Project-Specific QA Configuration

Place configuration files in your project's `qaConfig/` directory:

- `qaConfig/qaConfig.inc.bash` - Override pipeline settings
- `qaConfig/phpstan.neon` - PHPStan configuration
- `qaConfig/phpunit.xml` - PHPUnit configuration
- `qaConfig/php_cs.php` - PHP CS Fixer rules
- `qaConfig/rector.php` - Rector rules
- `qaConfig/infection.json` - Infection configuration

### Running Specific Tools

#### Via Workflow Dispatch

1. Go to Actions tab
2. Select "PHP QA Pipeline"
3. Click "Run workflow"
4. Select tool from dropdown

#### Via Workflow Modification

```yaml
- name: Run specific tool
  run: vendor/bin/qa -t phpstan
```

### Caching Strategy

The `php-qa-ci.yml` template uses two caches:

- **Composer dependencies** — keyed on `${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}`
  (this key does **not** include the PHP version).
- **QA tool PHARs + tool cache** (`vendor-phar/` and `var/qa/cache`) — keyed on the OS, the detected
  PHP version, and `hashFiles('**/phive.xml', '**/composer.lock')`.

There is no separate "PHPStan cache" block. Only the QA-tools cache key includes the PHP version.

## Workflow Features

The workflow includes:

- **Dynamic PHP version detection** from composer.json
- **Smart git cloning** (shallow by default, full when auto-commit enabled)
- **PHIVE integration** for PHAR tool management
- **Comprehensive caching** for speed
- **Auto-commit capability** with safety checks
- **Manual tool selection** via workflow_dispatch
- **Artifact storage** for test results and coverage
- **Extensive inline documentation**

## Troubleshooting

### Common Issues

**"php-qa-ci not installed" error**

```bash
composer require --dev lts/php-qa-ci:dev-php8.5@dev
```

**Permission denied for auto-commits**

- Check repository Settings -> Actions -> Workflow permissions
- Ensure "Read and write permissions" is selected

**Out of memory** (default is now 4G for all tools)

```yaml
env:
  phpqaMemoryLimit: 8G
```

**Infection timeout**

```yaml
env:
  useInfection: 0  # Disable mutation testing in CI
```

### Debugging Failed Builds

1. Download artifacts from failed run
2. Check `var/qa/` directory contents
3. Reproduce the CI **gate** locally. On GitHub Actions php-qa-ci auto-enables read-only mode, so a
   pending Rector / PHP CS Fixer change fails the run instead of being applied. `CI=true` only
   disables prompts — it does **not** make the run read-only. To reproduce the gate, set
   `QA_READONLY=1`:

```bash
QA_READONLY=1 vendor/bin/qa
```

## Best Practices

1. **Use branch protection** - Require QA checks to pass
2. **Cache aggressively** - Speeds up builds significantly
3. **Skip infection in CI** - Run locally for faster feedback
4. **Auto-commit carefully** - Only on feature branches, never on main
5. **Review workflow documentation** - The template has extensive inline comments
6. **Enable update-deps.yml** - Keep dependencies current automatically

## Migration from Travis/Jenkins

### From Travis CI

- Replace `.travis.yml` with `.github/workflows/qa.yml`
- Environment variables work similarly
- Artifacts replace Travis's build artifacts

### From Jenkins

- GitHub Actions is commit-triggered by default
- No need for webhooks or polling
- Use workflow_dispatch for manual triggers

## Integration with Other Services

### Codecov

```yaml
- name: Upload to Codecov
  uses: codecov/codecov-action@v3
  with:
    file: var/qa/phpunit_logs/coverage.clover
```

### Slack Notifications

```yaml
- name: Slack Notification
  if: failure()
  uses: 8398a7/action-slack@v3
  with:
    status: ${{ job.status }}
```

## References

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [shivammathur/setup-php](https://github.com/shivammathur/setup-php)
- [PHP QA CI Documentation](../README.md)
