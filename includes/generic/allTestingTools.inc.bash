# allTestingTools — testing phase. The ordered sequence is DERIVED from the tool
# registry: see qaRunPhase / QA_TOOL_PHASE in includes/generic/toolRegistry.inc.bash.
# phpunit and infection are gated off when phpqaQuickTests=1; infection
# additionally requires useInfection=1 (QA_TOOL_GATE[infection]=infection).
qaRunPhase testing
