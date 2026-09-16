# Fixture project rule index

A stand-in for a consuming project's own identifier index, used to exercise
`RuleDocResolver`'s handling of catalogues outside this package.

| Identifier                     | Rule class          | Requirement                                            |
| ------------------------------ | ------------------- | ------------------------------------------------------ |
| `fixtureApp.projectRule`       | `ProjectRule`       | A project rule with no remediation page                |
| `fixtureApp.projectRuleLinked` | `ProjectLinkedRule` | [A project rule that links to a page](project-rule.md) |
