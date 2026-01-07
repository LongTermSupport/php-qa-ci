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
- Actions → PHP QA Pipeline → Run workflow → Select tool

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
  skipUncommittedChangesCheck: 1   # Required
  AUTO_COMMIT_FIXES: 'false'       # Enable auto-commit of fixes
  phpUnitCoverage: 0                # Disable coverage
  phpqaQuickTests: 1                # Quick test mode
  useInfection: 0                   # Skip mutation testing
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
1. Go to Settings → Actions → General
2. Under "Workflow permissions":
   - Select "Read and write permissions"
   - Check "Allow GitHub Actions to create and approve pull requests" (if using auto-commits)

#### Branch Protection (Recommended)
1. Go to Settings → Branches
2. Add rule for main/master branch
3. Check "Require status checks to pass before merging"
4. Select "PHP QA Pipeline" as required check

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

The workflow caches:
- Composer dependencies
- QA tool PHARs
- PHPStan cache

Cache keys include PHP version to prevent conflicts.

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
composer require --dev lts/php-qa-ci:dev-php8.4
```

**Permission denied for auto-commits**
- Check repository Settings → Actions → Workflow permissions
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
3. Run locally with same configuration:
```bash
CI=true vendor/bin/qa
```

## Best Practices

1. **Use branch protection** - Require QA checks to pass
2. **Cache aggressively** - Speeds up builds significantly  
3. **Skip infection in CI** - Run locally for faster feedback
4. **Auto-commit carefully** - Only on feature branches, never on main
5. **Review workflow documentation** - The template has extensive inline comments

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