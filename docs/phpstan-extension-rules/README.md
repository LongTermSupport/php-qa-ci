# PHPStan identifiers without upstream pages

php-qa-ci installs these PHPStan extensions in every consuming project, and their upstream
publishes no page for the identifiers they print. `vendor/bin/rule-doc <identifier>` resolves
each one to the page here, offline. PHPStan's own identifiers and those of its first-party
extensions resolve to PHPStan's pages, carried in `vendor-docs/phpstan/`.

| Identifier                          | Extension                                             |
| ----------------------------------- | ----------------------------------------------------- |
| `typeCoverage.paramTypeCoverage`    | [type-coverage](typeCoverage.paramTypeCoverage.md)    |
| `typeCoverage.returnTypeCoverage`   | [type-coverage](typeCoverage.returnTypeCoverage.md)   |
| `typeCoverage.propertyTypeCoverage` | [type-coverage](typeCoverage.propertyTypeCoverage.md) |
| `typeCoverage.constantTypeCoverage` | [type-coverage](typeCoverage.constantTypeCoverage.md) |

A PHPStan release can also print an identifier its website has no page for yet. Until it has
one, the page here stands in; once the carried catalogue has its own, `rule-doc` reads that
instead, since the catalogue is looked up first.

| Identifier                                       | Since PHPStan                                              |
| ------------------------------------------------ | ---------------------------------------------------------- |
| `method.impureOverridePureUnlessParameterPassed` | [2.3.0](method.impureOverridePureUnlessParameterPassed.md) |
| `pureFunction.nonOptionalParameterPassed`        | [2.3.0](pureFunction.nonOptionalParameterPassed.md)        |
| `pureMethod.nonOptionalParameterPassed`          | [2.3.0](pureMethod.nonOptionalParameterPassed.md)          |
| `purePropertyHook.nonOptionalParameterPassed`    | [2.3.0](purePropertyHook.nonOptionalParameterPassed.md)    |
| `pureFunction.parameterPassedNotByRef`           | [2.3.0](pureFunction.parameterPassedNotByRef.md)           |
| `pureMethod.parameterPassedNotByRef`             | [2.3.0](pureMethod.parameterPassedNotByRef.md)             |
| `purePropertyHook.parameterPassedNotByRef`       | [2.3.0](purePropertyHook.parameterPassedNotByRef.md)       |

A page is named exactly for the identifier it documents, which is how `rule-doc` finds it. An
installed extension, or the shipped `phpstan.phar`, that starts printing a new identifier fails
`ForeignIdentifierCatalogueTest` until its page is here.
