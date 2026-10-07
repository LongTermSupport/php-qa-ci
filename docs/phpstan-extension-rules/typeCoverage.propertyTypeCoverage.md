# typeCoverage.propertyTypeCoverage

A share of the project's properties lower than the floor set with
`withTypeCoverageFloors(propertyType: N)` declares a native type. The finding repeats on every
untyped property, so each line it names is one place to raise the share.

Reported by [type-coverage](https://github.com/TomasVotruba/type-coverage), which php-qa-ci installs;
the floor and why it is a ratchet are in [PHPStan: type coverage](../tools/phpstan.md#type-coverage).

## The correct construction

Declare the property's type natively, not only in a docblock:

```php
// Reported: the type is a comment PHP never checks
/** @var list<string> */
private $names = [];

// Correct: PHP enforces it on every write
/** @var list<string> */
private array $names = [];
```

A typed property has no implicit `null` default, so one that is filled later is declared nullable
(`?Logger $logger = null`) or assigned in the constructor.
