# Static-analysis phase. The ordered sequence is DERIVED from the tool registry
# (SSoT, M-011): see qaRunPhase / QA_TOOL_PHASE in
# includes/generic/toolRegistry.inc.bash. PHPStan is gated off when
# phpqaQuickTests=1 (QA_TOOL_GATE[phpstan]=notQuick).
qaRunPhase staticAnalysis
