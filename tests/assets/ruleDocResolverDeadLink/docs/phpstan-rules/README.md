# Fixture rule index — dead link

A stand-in index whose one row links to a remediation page that does not exist.

Its own root, separate from `ruleDocResolver/`, because the resolver parses **every** row of
an index on any lookup: a dead link here would otherwise decide the outcome of the tests over
there, which is the whole hazard being pinned.

| Identifier                | Rule              | Requirement                                   |
| ------------------------- | ----------------- | --------------------------------------------- |
| `phpqaci.fixtureDeadLink` | `FixtureDeadRule` | [links to a page that is not here](absent.md) |
