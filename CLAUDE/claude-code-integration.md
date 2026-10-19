# Claude Code integration: what php-qa-ci deploys into a consumer

php-qa-ci ships Claude Code skills, agents and hooks, and deploys them into a consuming project's
`.claude/` on every `composer install` and `composer update` (the `SkillsDeployPlugin`), or by
hand:

```bash
vendor/lts/php-qa-ci/scripts/deploy-skills.bash vendor/lts/php-qa-ci .
```

The user-facing description (what the deploy does, the opt-out `PHP_QA_CI_DISABLE_CONFIG_PUSH`)
is [README.md](../README.md#claude-code-integration). Each hook's purpose, risk and configuration,
and the recommended default set (`php-qa-ci__auto-continue.py` and
`php-qa-ci__prevent-destructive-git.py` always; the others by team standard), are in
[.claude/hooks/README.md](../.claude/hooks/README.md), the single source of truth for the hooks.

Facts a contributor changing the deploy needs:

- The deploy is a set of phases under `scripts/lib/deploy-*.inc.bash`, driven by
  `scripts/deploy-skills.bash` and its manifest (`scripts/lib/deploy-manifest.inc.bash`).
- The classic `php-qa-ci__*.py` hooks are copied, made executable and registered in
  `.claude/settings.json` when no hooks daemon is detected. The legacy-name migration (old hook
  names without the `php-qa-ci__` prefix, rewritten in `.claude/settings.json`) runs on every
  deploy where `.claude/settings.json` exists (`scripts/lib/deploy-classic-hooks.inc.bash`).
- With the hooks daemon at v3.67.0 or later, the daemon phase also declares
  `subagent_full_qa_blocker`, so a sub-agent's full pipeline run is denied and the full gate stays
  with the coordinating session. The logic is [src/HooksDaemon/](../src/HooksDaemon/) behind
  `bin/hooks-daemon-full-qa-blocker`; what counts as full, why, and how the config edit keeps
  comments is [docs/hooks-daemon-full-qa-blocker.md](../docs/hooks-daemon-full-qa-blocker.md).
- Skills and agents ship to every consumer through the deploy manifest, so a skill's description
  is resident in every consumer session: a new one has a cost beyond this repository.
