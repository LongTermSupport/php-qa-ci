# PHPStan extension rules without upstream pages

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

A page is named exactly for the identifier it documents, which is how `rule-doc` finds it. An
installed extension that starts printing a new identifier fails `ForeignIdentifierCatalogueTest`
until its page is here.
