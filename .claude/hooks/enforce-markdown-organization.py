#!/usr/bin/env python3
"""
Claude Code Hook: Enforce Markdown File Organization

Prevents markdown documentation from littering the filesystem.
Enforces strict organization rules for where *.md files can be written.

ALLOWED markdown locations:
  1. CLAUDE/Plan/* or CLAUDE/plan/* - Plan-specific documentation (convention)
  2. CLAUDE/ (root only)   - Generic LLM-focused documentation (use sparingly)
  3. docs/                 - Human-facing documentation (guides, tutorials, API docs)
  4. untracked/            - Ad-hoc temporary documentation (NOT tracked in git)
  5. .claude/agents/*.md   - Agent definitions
  6. .claude/skills/*/*.md - Skills documentation (with warning for non-SKILL.md files)
  7. CLAUDE.md (any path)  - Ad-hoc LLM instructions for any directory
  8. README.md (any path)  - Ad-hoc human instructions for any directory

BLOCKED locations (except CLAUDE.md/README.md which are always allowed):
  ✗ .claude/hooks/         - Scripts only, no docs
  ✗ Root directory         - Prevents clutter
  ✗ Any other *.md files   - Must use organized locations above

IMPORTANT: If this hook blocks your documentation and you believe it should be allowed,
please raise this with a human to review the project's documentation structure.

Configuration:
  Set PROJECT_ROOT_INDICATORS environment variable to customize detection:
  export PROJECT_ROOT_INDICATORS="composer.json,package.json,.git"
"""

import json
import os
import re
import sys
from pathlib import Path


def find_project_root(start_path: str) -> Path:
    """Find project root by looking for indicator files.

    Walks up from start_path looking for:
    - composer.json (PHP projects)
    - package.json (Node projects)
    - .git directory

    Falls back to os.getcwd() if not found.
    """
    indicators_env = os.environ.get('PROJECT_ROOT_INDICATORS', 'composer.json,package.json,.git')
    indicators = [i.strip() for i in indicators_env.split(',')]

    current = Path(start_path).resolve()

    # Walk up the directory tree
    for parent in [current] + list(current.parents):
        for indicator in indicators:
            if (parent / indicator).exists():
                return parent

    # Fallback to current working directory
    return Path(os.getcwd())


def find_current_plan(project_root: Path):
    """Detect the current plan being worked on.

    Returns tuple: (plan_number, plan_folder_name, plan_path) or (None, None, None)
    """
    # Support both CLAUDE/Plan and CLAUDE/plan
    plan_dir = project_root / "CLAUDE" / "Plan"
    if not plan_dir.exists():
        plan_dir = project_root / "CLAUDE" / "plan"
    if not plan_dir.exists():
        return None, None, None

    # Find all plan folders matching pattern: NNN-kebab-case
    plan_folders = sorted([
        d for d in plan_dir.iterdir()
        if d.is_dir() and re.match(r'^\d{3}-', d.name)
    ], reverse=True)

    if plan_folders:
        latest = plan_folders[0]
        # Extract plan number
        match = re.match(r'^(\d{3})-', latest.name)
        if match:
            plan_num = match.group(1)
            return plan_num, latest.name, latest

    return None, None, None


def is_agent_file(file_path: str) -> bool:
    """Check if this is an agent definition file.

    Agent files are any .md files in .claude/agents/ directory.
    """
    return bool(re.match(r'^\.?/?claude/agents/[^/]+\.md$', file_path, re.IGNORECASE))


def is_skill_definition(file_path: str) -> bool:
    """Check if this is a SKILL.md file (main skill definition).
    """
    # Pattern: .claude/skills/*/SKILL.md (case-insensitive for SKILL.md)
    return bool(re.match(r'^\.?/?claude/skills/[^/]+/SKILL\.md$', file_path, re.IGNORECASE))


def is_skill_documentation(file_path: str) -> bool:
    """Check if this is documentation in a skill directory.

    Returns True for any .md file in .claude/skills/*/ directories.
    """
    return bool(re.match(r'^\.?/?claude/skills/[^/]+/[^/]+\.md$', file_path, re.IGNORECASE))


def is_adhoc_instruction_file(file_path: str) -> bool:
    """Check if this is an ad-hoc instruction file (CLAUDE.md or README.md).

    These files are allowed in ANY directory as they provide contextual
    instructions for that specific location.

    Examples:
    - README.md (root)
    - .claude/hooks/README.md
    - scripts/CLAUDE.md
    - src/components/README.md
    """
    filename = Path(file_path).name.lower()
    return filename in ['claude.md', 'readme.md']


def validate_markdown_location(file_path: str, project_root: Path) -> tuple:
    """Validate if markdown file is in allowed location.

    Returns: (is_allowed, location_type, reason, suggestion)
    """
    # Normalize path (remove leading/trailing slashes and make relative to project root)
    normalized = file_path.strip('/')
    if normalized.startswith('workspace/'):
        normalized = normalized[10:]  # Remove 'workspace/'

    # Make path relative to project root if it's absolute
    file_path_obj = Path(file_path).resolve()
    try:
        normalized = str(file_path_obj.relative_to(project_root))
    except ValueError:
        # File is outside project root, use as-is
        pass

    # Check special cases that are always allowed

    # CLAUDE.md and README.md are allowed in ANY directory
    if is_adhoc_instruction_file(normalized):
        return True, "ADHOC_INSTRUCTIONS", "Ad-hoc instruction files (CLAUDE.md/README.md) allowed anywhere", None

    # Check for skill files (SKILL.md or other docs in skill directories)
    if is_skill_definition(normalized):
        return True, "SKILL_DEFINITION", "Skill definition file is allowed", None

    if is_skill_documentation(normalized):
        # Allow but warn for non-SKILL.md files in skills directories
        return True, "SKILL_DOCS_WARNING", "Skill documentation allowed (prefer SKILL.md for main definitions)", None

    # Check for agent files
    if is_agent_file(normalized):
        return True, "AGENT_DEFINITION", "Agent definition file is allowed", None

    # Check allowed locations

    # 1. CLAUDE/Plan/NNN-*/ or CLAUDE/plan/NNN-*/ - Plan-specific documentation
    if re.match(r'^CLAUDE/(P|p)lan/\d{3}-[^/]+/[^/]+\.md$', normalized, re.IGNORECASE):
        return True, "PLAN_DOCS", "Plan-specific documentation is allowed", None

    # 2. CLAUDE/ root level only (no subdirs)
    if normalized.lower().startswith('claude/') and '/' not in normalized[7:]:
        if normalized.lower() == 'claude/readme.md':
            return True, "CLAUDE_README", "CLAUDE root README.md is allowed", None
        # Generic CLAUDE-level docs (allow but track)
        return True, "CLAUDE_ROOT", "Root-level CLAUDE documentation is allowed", None

    # 3. docs/ - Human-facing documentation
    if normalized.lower().startswith('docs/'):
        return True, "HUMAN_DOCS", "Human-facing documentation is allowed", None

    # 5. untracked/ - Temporary ad-hoc documentation
    if normalized.lower().startswith('untracked/'):
        return True, "TEMP_DOCS", "Temporary documentation in untracked/ is allowed", None

    # Check blocked locations

    # .claude/hooks/ - NO documentation
    if re.match(r'^\.claude/hooks/[^/]+\.md$', normalized, re.IGNORECASE):
        plan_num, plan_name, _ = find_current_plan(project_root)
        suggestion = ""
        if plan_num:
            suggestion = f"CLAUDE/Plan/{plan_num}-{plan_name}/"
        else:
            suggestion = "untracked/"
        return False, "HOOKS_DIR", "No documentation allowed in .claude/hooks/", suggestion

    # Root directory *.md (except README.md which is handled by is_adhoc_instruction_file)
    if '/' not in normalized and normalized.lower().endswith('.md'):
        return False, "ROOT_DIR", "Documentation files clutter root directory", "CLAUDE/ or CLAUDE/Plan/"

    # Default: any other location
    return False, "UNKNOWN_LOCATION", "Markdown files must follow organization rules", "CLAUDE/Plan/ or untracked/"


def get_suggestion_detail(file_path: str, suggestion: str, project_root: Path) -> str:
    """Get detailed suggestion based on context."""
    plan_num, plan_name, _ = find_current_plan(project_root)

    lines = []

    if 'Plan' in suggestion or plan_num:
        lines.append(f"1. CLAUDE/Plan/{plan_num}-{plan_name}/ - Docs for current plan")
        lines.append(f"   Suggested: CLAUDE/Plan/{plan_num}-{plan_name}/{Path(file_path).name}")

    lines.append("2. CLAUDE/ (root only) - Generic LLM docs")
    lines.append("   Only for truly project-wide, persistent documentation")
    lines.append("   Examples: CLAUDE.md, PlanWorkflow.md")

    lines.append("3. docs/ - Human-facing documentation")
    lines.append("   User guides, tutorials, API docs")

    lines.append("4. untracked/ - Ad-hoc temporary docs")
    lines.append("   Implementation notes, test outputs, scratch files")
    lines.append(f"   Suggested: untracked/{Path(file_path).name}")

    lines.append("5. .claude/agents/ - Agent definitions")
    lines.append("   Agent .md files only")

    lines.append("6. .claude/skills/*/ - Skills documentation")
    lines.append("   SKILL.md and related docs")

    return "\n".join(lines)


def allow_and_exit():
    """Output empty JSON and exit - required to prevent 'hook error' messages."""
    print('{}')
    sys.exit(0)


def block_and_exit(reason: str, file_path: str, suggestion: str, project_root: Path):
    """Block the operation with clear reason."""
    error_lines = [
        "",
        "MARKDOWN FILE IN WRONG LOCATION",
        "",
        "Markdown files must follow project organization rules.",
        "",
        f"Attempted to write: {file_path}",
        "",
        "This location is NOT allowed. Markdown files can only be written to:",
        "",
    ]

    error_lines.append(get_suggestion_detail(file_path, suggestion, project_root))

    error_lines.extend([
        "",
        "---",
        "",
        "CHOOSE THE RIGHT LOCATION:",
        "- Is this for the current plan? -> CLAUDE/Plan/{plan-number}-*/",
        "- Is this temporary/ad-hoc? -> untracked/",
        "- Is this for humans? -> docs/",
        "- Is this generic LLM context? -> CLAUDE/ (very rare!)",
        "",
        "ALLOWED LOCATIONS:",
        "  ✓ CLAUDE/Plan/{plan}/ - Plan-specific documentation",
        "  ✓ CLAUDE/ (root) - Generic LLM documentation",
        "  ✓ CLAUDE/research/ - Structured research (NO work logs)",
        "  ✓ docs/ - Human-facing documentation",
        "  ✓ untracked/ - Temporary ad-hoc docs",
        "",
        "BLOCKED LOCATIONS:",
        "  X .claude/hooks/ - Only scripts, no docs",
        "  X .claude/agents/ - Agent definitions only",
        "  X .claude/skills/*/ - Skill definitions only",
        "  X Root directory - Prevents clutter",
        "",
    ])

    block_response = {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "deny",
            "permissionDecisionReason": "\n".join(error_lines)
        }
    }

    json.dump(block_response, sys.stdout)
    sys.exit(0)


def main():
    # Read hook input from stdin
    try:
        hook_input = json.load(sys.stdin)
    except json.JSONDecodeError:
        # If we can't parse input, allow (fail open)
        allow_and_exit()

    # Only check Write and Edit tools
    tool_name = hook_input.get("tool_name")
    if tool_name not in ["Write", "Edit"]:
        allow_and_exit()

    # Only check *.md files
    file_path = hook_input.get("tool_input", {}).get("file_path", "")
    if not file_path or not file_path.lower().endswith(".md"):
        allow_and_exit()

    # Detect project root
    project_root = find_project_root(file_path)

    # Validate location
    is_allowed, location_type, reason, suggestion = validate_markdown_location(file_path, project_root)

    if is_allowed:
        # Print warning for skills documentation that isn't SKILL.md
        if location_type == "SKILL_DOCS_WARNING":
            import sys
            warning = (
                f"\n⚠️  WARNING: Creating documentation in .claude/skills/ directory\n"
                f"   File: {file_path}\n"
                f"   While this is allowed, prefer using SKILL.md for the main skill definition.\n"
                f"   Additional docs should explain implementation details or provide context.\n\n"
            )
            sys.stderr.write(warning)
        allow_and_exit()

    # Block with detailed error
    block_and_exit(reason, file_path, suggestion or "untracked/", project_root)


if __name__ == "__main__":
    main()
