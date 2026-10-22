# Plan 00022: handoff to the coordinator after Phase 6

The executing sub-agents have finished every task they can run. What remains needs a
logged-in session, the full gate, or an Owner decision.

## Done

- Phases 0–3: see git history (`636ea8dc` to `1b4d4894`).
- 5.3 `3da48551` (`autoMemoryEnabled: false`); 4.1 and 4.2 `425bd2ff`; 5.1, 5.2 and 6.5
  `34c404a3`; 6.1 and 6.6 drafts `b8cfbee1`; 6.4 review fixes `56a424b0`.
- Root `CLAUDE.md`: 635 lines, about 26.5k tokens (chars ÷ 4; baseline about 42.6k). The
  hand-written part is 81 lines, about 1.5k tokens. Both generated regions are unchanged.

## Left to the coordinator

- Task 6.2: probe the new nested routing files and path rules. Read one matching file for
  each: `src/Pipeline/Tool/ToolRegistry.php` → `src/Pipeline/CLAUDE.md`;
  `src/PHPStan/RuleDocResolver.php` → `src/PHPStan/CLAUDE.md`; `scripts/tool-install.bash` →
  `scripts/CLAUDE.md`; `bin/phpstan` (an extensionless stub) → `phpqaci-shell-scripts.md`;
  `CHANGELOG.md` → `phpqaci-releases.md`; `phive.xml` → `phpqaci-tool-delivery.md`. Then probe
  the scratch-consumer case.
- Task 6.3: the five headless scenario prompts.
- Task 1.2 (c) and the `/context` figures.
- Task 6.7: the pre-push gate, the PR and PR verification. This branch stacks on
  `chore/hooks-daemon-v3.70.0`.
- File the Task 6.6 drafts in this folder (`upstream-hooks-daemon-report-draft.md`, and the two
  `issue-draft-*.md`) once the Owner agrees.
