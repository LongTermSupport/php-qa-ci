# Fixture rule index

A minimal stand-in for `docs/phpstan-rules/README.md`, used to exercise
`RuleDocResolver`'s handling of a row that links to no remediation page.

This case cannot be tested against the real index: every rule shipped by this package now
has a page, which is the point of Plan 00010 Task 3.1, and the release guard exists to keep
it that way. Pinning the behaviour to whichever real rule happened to be undocumented made
documenting that rule a test failure.

| Identifier              | Rule                | Requirement                                      |
| ----------------------- | ------------------- | ------------------------------------------------ |
| `phpqaci.fixtureNoPage` | `FixtureNoPageRule` | A row whose requirement cell carries no link     |
| `phpqaci.fixtureLinked` | `FixtureLinkedRule` | [A row that links to a page](fixture-linked.md)  |
| `phpqaci.fixtureNested` | `FixtureNestedRule` | [`#[\Attr]` in the link text](fixture-nested.md) |
