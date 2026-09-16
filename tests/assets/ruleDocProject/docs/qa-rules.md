# Fixture project rule index

A stand-in for a consuming project's own identifier index, used to exercise
`RuleDocResolver`'s handling of catalogues outside this package.

The summary is NOT the last column here: `Origin` is. This is the real shape a
consuming project writes, and the resolver has to read the header rather than
assume the trailing cell.

| Identifier                     | Rule class          | Forbids                                                | Origin     |
| ------------------------------ | ------------------- | ------------------------------------------------------ | ---------- |
| `fixtureApp.projectRule`       | `ProjectRule`       | A project rule with no remediation page                | Plan 00001 |
| `fixtureApp.projectRuleLinked` | `ProjectLinkedRule` | [A project rule that links to a page](project-rule.md) | Plan 00002 |
