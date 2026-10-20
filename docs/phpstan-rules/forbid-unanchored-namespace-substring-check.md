# `phpqaci.unanchoredNamespaceSubstringCheck` — compare namespace segments, not a substring of the name

**Rule**: `ForbidUnanchoredNamespaceSubstringCheckRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A call to `str_contains`, `strpos` or `stripos` that looks for a bare namespace-segment word, such
as `Generated` or `PHPStan` (a capital letter, then letters and digits), anywhere in a class or
namespace name. The word is read as a literal, a constant, or each value of a loop over a constant
list:

```php
private const array SKIPPED = ['PHPStan', 'Migrations', 'Generated'];

$className = $scope->getNamespace() . '\\' . $node->name->toString();
foreach (self::SKIPPED as $namespace) {
    if (str_contains($className, $namespace)) {
        return []; // App\GeneratedReports\Invoice is skipped too
    }
}
```

The subject is a class or namespace name when the code takes it from where names come from:

- a `class-string`: `$object::class`, `get_class()`, a parameter typed `class-string`;
- `Scope::getNamespace()`, `ClassReflection::getName()`, `ReflectionClass::getName()` or
  `getNamespaceName()`;
- a parsed node's `namespacedName`;
- a concatenation that starts with one of those, such as the `$className` above;
- a local variable assigned one of those in the same function.

## Why this is a hazard

The check means "is this class in a `Generated` namespace", and asks "does the name contain the
letters `Generated`". `App\GeneratedReports\Invoice` and `App\Billing\RegeneratedInvoice` both
contain them, as `App\PHPStanAdapter\Client` contains `PHPStan`. A rule that exempts the namespace then exempts classes
nobody chose to exempt, and the run stays green over code the rule never looked at.

## The correct construction

Compare whole namespace segments:

```php
private const array SKIPPED = ['PHPStan', 'Migrations', 'Generated'];

$segments = explode('\\', $className);
if ([] !== array_intersect(self::SKIPPED, $segments)) {
    return [];
}
```

For one word, `in_array('Generated', explode('\\', $className), true)`. If a prefix is what you
mean, say so with the separator: `str_starts_with($className, 'App\Generated\\')`.

## The rule's bounds

What it does not report:

- a needle bounded by a separator, such as `'\Generated\\'`, which names a segment;
- `str_starts_with` and `str_ends_with`, which are anchored at one end;
- a needle that is not a single word, such as `'generated'`, `'src/'` or `'\\'`;
- the word `Tests`, which
  [`phpqaci.unanchoredTestsSubstringCheck`](forbid-unanchored-tests-substring-check.md) reports
  with any subject;
- a subject the rule cannot trace to a class name: a plain `string` parameter, a property, or a
  variable written other than by plain assignment, such as by a `foreach` or by reference. A
  string PHPStan types only as `string` carries no record of where it came from, so the rule
  reads the sources listed above and nothing else.

[`phpqaci.unanchoredVendorSubstringCheck`](forbid-unanchored-vendor-substring-check.md) is the same
kind of rule for deciding whether a file is a dependency's.
