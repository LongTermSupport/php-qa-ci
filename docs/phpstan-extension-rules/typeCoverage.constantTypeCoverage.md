# typeCoverage.constantTypeCoverage

A share of the project's class constants lower than the floor set with
`withTypeCoverageFloors(constantType: N)` declares a native type. The finding repeats on every
untyped constant, so each line it names is one place to raise the share.

Reported by [type-coverage](https://github.com/TomasVotruba/type-coverage), which php-qa-ci installs;
the floor and why it is a ratchet are in [PHPStan: type coverage](../tools/phpstan.md#type-coverage).

## The correct construction

Declare the constant's type, which PHP 8.3 and later enforce, including on a subclass that
overrides it:

```php
// Reported: a subclass may redefine it as any type
public const LIMIT = 10;

// Correct
public const int LIMIT = 10;
```
