# src/Pipeline: routing

The pipeline itself: the CLI, configuration, the tool registry, the lanes and the runner.

- How it fits together (components, a run end to end, the tool runner's invariants):
  [CLAUDE/architecture.md](../../CLAUDE/architecture.md)
- A new check, as a new lane or an assertion in an existing one:
  [CLAUDE/tool-boundaries.md](../../CLAUDE/tool-boundaries.md)
- What a consumer sees of a lane: its page under [docs/tools/](../../docs/tools/)
- Phases, preparation and hooks as a consumer sees them: [docs/pipeline.md](../../docs/pipeline.md)
