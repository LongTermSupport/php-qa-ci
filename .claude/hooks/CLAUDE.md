# PHP-QA-CI Claude Code Hooks - Deployment System

This directory contains Claude Code hooks that are **automatically deployed** to consuming projects via Composer plugin.

## Understanding Automatic Deployment

### Deployment Mechanism

When a project installs or updates `lts/php-qa-ci`, hooks are automatically deployed through:

1. **Composer Plugin**: `/src/ComposerPlugin/SkillsDeployPlugin.php`
   - Subscribes to `POST_INSTALL_CMD` and `POST_UPDATE_CMD` events
   - Runs automatically after `composer install` or `composer update`

2. **Deployment Script**: `/scripts/deploy-skills.bash`
   - Copies all `.py` files from this directory to project's `.claude/hooks/`
   - Makes them executable with `chmod +x`
   - Registers them in project's `.claude/settings.json` under `PreToolUse` hooks
   - Ensures proper timeout configuration (5 seconds default)

3. **Target Location**: `{project-root}/.claude/hooks/`
   - All hooks are copied to this directory
   - Existing hooks are preserved (no overwrite of project-specific hooks)
   - Registration is idempotent - hooks won't be registered twice

### Manual Deployment Command

To manually deploy or re-deploy:

```bash
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

## Hook Naming Convention

### Required Pattern for New Hooks: `php-qa-ci__` Prefix

**IMPORTANT**: All hooks deployed from this package should follow the naming pattern:
```
php-qa-ci__<descriptive-name>.py
```

This prefix clearly identifies the hook's source and helps users distinguish between:
- Hooks deployed by php-qa-ci (e.g., `php-qa-ci__auto-continue.py`)
- Project-specific hooks (e.g., `project-validate-schema.py`)

### Current Hook Names

All hooks now use the `php-qa-ci__` prefix:
- `php-qa-ci__auto-continue.py`
- `php-qa-ci__prevent-destructive-git.py`
- `php-qa-ci__discourage-git-stash.py`
- `php-qa-ci__block-plan-time-estimates.py`
- `php-qa-ci__validate-claude-readme-content.py`
- `php-qa-ci__enforce-markdown-organization.py`

### Automatic Migration from Legacy Names

The deployment script automatically migrates old hook names to new ones:
- Old: `.claude/hooks/auto-continue.py` → New: `.claude/hooks/php-qa-ci__auto-continue.py`
- Old: `.claude/hooks/prevent-destructive-git.py` → New: `.claude/hooks/php-qa-ci__prevent-destructive-git.py`
- And so on for all hooks...

This migration happens during `composer install/update` and updates `.claude/settings.json` automatically.

Projects with old hook names will be automatically migrated on the next composer operation.

### How to Add New Hooks to This Package

When adding new hooks to this directory:

1. **Use the prefix pattern**: Name your hook `php-qa-ci__<feature-name>.py`
   ```bash
   # Correct naming
   php-qa-ci__validate-phpstan-level.py
   php-qa-ci__enforce-test-coverage.py

   # Incorrect naming (no prefix)
   validate-phpstan-level.py
   enforce-test-coverage.py
   ```

2. **Follow the standard format**: Use JSON stdin/stdout format (see README.md)

3. **Include fail-open behavior**: Hooks should allow operations on error

4. **Test locally first**:
   ```bash
   # Test with sample input
   echo '{"tool_name": "Bash", "tool_input": {...}}' | python3 php-qa-ci__my-hook.py
   ```

5. **Update README.md**: Document the new hook's purpose, blocked patterns, and escape hatches

6. **Update this file**: Add the new hook to the naming convention examples

### Benefits of the Naming Convention

- **Clear Source Identification**: Users immediately know which hooks come from php-qa-ci
- **Namespace Separation**: Prevents naming conflicts with project-specific hooks
- **Easier Troubleshooting**: Users can identify php-qa-ci hooks when debugging
- **Documentation Clarity**: Makes documentation more precise about which hooks are package-provided

## Hook Documentation Reference

For detailed information about what each hook does:
- **README.md** in this directory - Comprehensive guide for all hooks
- Individual hook source files - Comments explain specific behavior

## How to Test Hooks Before Deployment

To test a hook before deployment:

```bash
# Create test input
cat > test-input.json << 'EOF'
{
  "tool_name": "Bash",
  "tool_input": {
    "command": "git reset --hard"
  }
}
EOF

# Test the hook
python3 .claude/hooks/php-qa-ci__prevent-destructive-git.py < test-input.json
echo "Exit code: $?"
```

Expected results:
- **Allow**: Empty JSON `{}` with exit code 0
- **Block**: JSON with `permissionDecision: deny` and exit code 0

## How to Modify Deployed Hooks

### In Consuming Projects

Projects can override deployed hooks by:

1. **Modifying in place**: Edit `.claude/hooks/<hook-name>.py` directly
   - **Warning**: Changes will be overwritten on next `composer update`

2. **Disabling specific hooks**: Remove from `.claude/settings.json`
   - Edit the `hooks.PreToolUse[0].hooks` array
   - Remove the hook entry you want to disable

3. **Creating project-specific variants**:
   - Copy the hook with a new name (e.g., `project-prevent-destructive-git.py`)
   - Modify as needed
   - Register in `.claude/settings.json`
   - Disable the original php-qa-ci hook

### In PHP-QA-CI Package

To modify hooks for all consuming projects:

1. Edit the hook in this directory (`vendor/lts/php-qa-ci/.claude/hooks/`)
2. Test thoroughly with multiple scenarios
3. Update README.md documentation
4. Commit changes to php-qa-ci repository
5. Tag a new release
6. Consuming projects get updates on next `composer update`

## Integration with Skills and Agents

Hooks are deployed alongside:
- **Skills**: Model-invoked entry points in `.claude/skills/`
- **Agents**: Task executors in `.claude/agents/`

All three components work together to provide:
- **Guardrails** (hooks) - Prevent harmful operations
- **Automation** (skills) - Streamline workflows
- **Specialized Execution** (agents) - Handle complex tasks

## Troubleshooting Deployment Issues

### Hooks Not Deployed

If hooks aren't deployed after `composer install/update`:

1. Check plugin is registered:
   ```bash
   composer config allow-plugins.lts/php-qa-ci-plugin
   ```

2. Manually run deployment:
   ```bash
   vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
   ```

### Hooks Not Executing

If hooks aren't running:

1. Check they're executable:
   ```bash
   ls -l .claude/hooks/*.py
   ```
   All should show `-rwxr-xr-x`

2. Check registration in `.claude/settings.json`:
   ```bash
   cat .claude/settings.json | grep -A20 '"hooks"'
   ```

3. Try running hook manually:
   ```bash
   echo '{}' | python3 .claude/hooks/php-qa-ci__auto-continue.py
   ```

### Python Errors

If hooks fail with Python errors:

1. Check Python version: `python3 --version` (requires 3.6+)
2. Check for syntax errors: `python3 -m py_compile .claude/hooks/<hook>.py`
3. Review hook output in Claude Code for error messages

## Related Documentation

- **README.md** - Comprehensive hook documentation and usage guide
- **deploy-skills.bash** - Deployment script source
- **SkillsDeployPlugin.php** - Composer plugin source
- **Project's .claude/hooks/CLAUDE.md** - Project-specific hook documentation
