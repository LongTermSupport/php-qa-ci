# typeCoverage.returnTypeCoverage

A share of the project's functions and methods lower than the floor set with
`withTypeCoverageFloors(returnType: N)` declares a native return type. The finding repeats on every
function without one, so each line it names is one place to raise the share.

Reported by [type-coverage](https://github.com/TomasVotruba/type-coverage), which php-qa-ci installs;
the floor and why it is a ratchet are in [PHPStan: type coverage](../tools/phpstan.md#type-coverage).

## The correct construction

Declare the return type natively, not only in a docblock:

```php
// Reported: the type is a comment PHP never checks
/** @return list<string> */
public function names()

// Correct: PHP enforces it on every return
/** @return list<string> */
public function names(): array
```

A function that returns nothing declares `void`, one that never returns declares `never`, and one
whose result has no single type declares the union or `mixed` explicitly.
