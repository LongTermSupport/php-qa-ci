# Hooks Daemon - Active Configuration

> Generated on 2026-07-15 (v3.41.0) by `generate-docs`. Regenerate: `$PYTHON -m claude_code_hooks_daemon.daemon.cli generate-docs`

## Active Handlers

### PreToolUse (20 handlers)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 10 | destructive_git | BLOCKING | Block destructive git commands that permanently destroy data |
| 11 | daemon_location_guard | BLOCKING | Prevent agents from cd-ing into .claude/hooks-daemon and running commands |
| 11 | sed_blocker | BLOCKING | Block sed used for file modification - Claude gets sed wrong and causes file destruction |
| 12 | absolute_path | BLOCKING | Require absolute paths for Read/Write/Edit tool file_path parameters |
| 13 | error_hiding_blocker | BLOCKING | Block error-hiding patterns in code written via Write or Edit tools |
| 14 | curl_pipe_shell | TERMINAL | Block curl/wget piped to shell commands |
| 15 | pipe_blocker | BLOCKING | Block expensive commands piped to tail/head to prevent information loss |
| 15 | security_antipattern | BLOCKING | Block Write/Edit of files containing security antipatterns |
| 16 | root_recursion_guard | BLOCKING | Block recursive scanners (grep -r, find, fd, rg, ...) rooted at ``/``/home/etc |
| 16 | worktree_file_copy | BLOCKING | Prevent copying files between worktrees and main repo |
| 17 | git_stash | BLOCKING | Block or warn about git stash based on mode configuration |
| 18 | dangerous_permissions | TERMINAL | Block chmod 777 and dangerous permission commands |
| 19 | lock_file_edit_blocker | TERMINAL | Block direct editing of package manager lock files |
| 20 | pip_break_system | TERMINAL | Block pip install --break-system-packages commands |
| 21 | sudo_pip | TERMINAL | Block sudo pip install commands |
| 40 | gh_issue_comments | BLOCKING | Ensure gh issue view commands always include --comments flag |
| 40 | gh_pr_comments | BLOCKING | Ensure gh pr view commands always include --comments flag |
| 42 | global_npm_advisor | NON-TERMINAL | Advise on global npm/yarn package installations |
| 55 | web_search_year | ADVISORY | Validate WebSearch queries don't use outdated years |
| 57 | daemon_docs_guard | ADVISORY | Warn when reading from the hooks-daemon internal CLAUDE/ docs directory |

### PostToolUse (4 handlers)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 10 | bash_error_detector | ADVISORY | Detect errors and warnings in Bash command output |
| 27 | git_hooks_executable_fixer | NON-TERMINAL | Detect git's "not set as executable" hint and fix the hooks automatically |
| 28 | background_process_tracker | ADVISORY | Track backgrounded Bash processes and advise on watchdog/harvest (never kills) |
| 30 | recovery_cron_advisor | ADVISORY | Advisory handler that manages failsafe recovery cron across plan lifecycle |

### SessionStart (10 handlers)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 10 | yolo_container_detection | ADVISORY | Detects YOLO container environments using precise OS-level container markers |
| 50 | project_handler_load_checker | ADVISORY | Loudly alert at session start when project handlers failed to load |
| 51 | hook_registration_checker | ADVISORY | Validate hook registrations in Claude Code settings on session start |
| 52 | optimal_config_checker | ADVISORY | Check Claude Code environment for optimal configuration on session start |
| 53 | git_filemode_checker | ADVISORY | Warn when git core.fileMode=false is detected |
| 54 | gitignore_safety_checker | ADVISORY | Warn when required .claude/ paths are absent from .gitignore |
| 55 | suggest_status_line | ADVISORY | Suggest setting up daemon-based statusline on session start |
| 56 | version_check | ADVISORY | Check daemon version against latest GitHub release on new sessions |
| 57 | plan_qa_sweep | ADVISORY | Advisory SessionStart sweep over the plan tree (silent when clean) |
| 58 | ccy_supervisor_integrity | ADVISORY | Advisory: warn when the ccy supervisor is armed but its files are unsafe |

### SessionEnd (1 handler)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 10 | cleanup | NON-TERMINAL | Clean up temporary files when session ends |

### UserPromptSubmit (2 handlers)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 10 | git_context_injector | CONTEXT | Inject current git status as context when user submits a prompt |
| 54 | post_clear_auto_execute | ADVISORY | Inject execution guidance on the first prompt of a new session |

### PermissionRequest (1 handler)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 10 | auto_approve_reads | TERMINAL | Auto-approve read-only tool permission requests |

### Stop (5 handlers)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 10 | auto_continue_stop | TERMINAL | Intercept Stop events and enforce explicit stop reasons or auto-continue |
| 30 | hedging_language_detector | ADVISORY | Detect hedging language that signals guessing instead of researching |
| 58 | dismissive_language_detector | ADVISORY | Detect dismissive language that signals avoiding work |
| 100 | remind_prompt_library | ADVISORY | Remind to capture successful prompts to the library |
| 100 | subagent_completion_logger | NON-TERMINAL | Log subagent completion events to a JSONL file |

### Status (11 handlers)

| Priority | Handler | Behavior | Description |
|----------|---------|----------|-------------|
| 2 | multithread_indicator | NON-TERMINAL | Show this thread's rank among live Agent-View threads (``🧵 Y/X``) |
| 10 | model_context | NON-TERMINAL | Format model name with effort level and color-coded context percentage |
| 11 | environment_indicator | NON-TERMINAL | Show 💻 (desktop/host) or a container icon (🐳 docker / 📦 podman / 🧊 lxc) |
| 14 | current_time | NON-TERMINAL | Display current local time in status line (24-hour format, no seconds) |
| 20 | git_branch | NON-TERMINAL | Show current git branch with magicmonty-style status icons if in a git repo |
| 25 | git_repo_name | NON-TERMINAL | Show git repository name at start of status line |
| 25 | working_directory | NON-TERMINAL | Display working directory when it differs from project root |
| 28 | startup_cleanup | NON-TERMINAL | Show 🧹 briefly after daemon startup to indicate stale-file cleanup ran |
| 30 | daemon_stats | NON-TERMINAL | Show daemon health: uptime, memory, last error, log level |
| 40 | account_display | NON-TERMINAL | Display Claude account username in status line |
| 60 | usage_tracking | NON-TERMINAL | Display daily and weekly token usage percentages |

## Quick Config Reference

**Config file**: `.claude/hooks-daemon.yaml`
**Enable/disable**: Set `enabled: true/false` under handler name
**Handler options**: Set under `options:` key per handler
