# typeCoverage.paramTypeCoverage

A share of the project's parameters lower than the floor set with
`withTypeCoverageFloors(paramType: N)` carries a native type. The finding repeats on every untyped
parameter, so each line it names is one place to raise the share.

Reported by [type-coverage](https://github.com/TomasVotruba/type-coverage), which php-qa-ci installs;
the floor and why it is a ratchet are in [PHPStan: type coverage](../tools/phpstan.md#type-coverage).

## The correct construction

Declare the parameter's type natively, not only in a docblock:

```php
// Reported: the type is a comment PHP never checks
/** @param list<string> $names */
public function greet($names): string

// Correct: PHP enforces it on every call
/** @param list<string> $names */
public function greet(array $names): string
```

Where no single type fits, declare the union (`int|string`) or `mixed` explicitly. An explicit
`mixed` counts as typed: it states that any value is accepted, which an omitted type does not.
