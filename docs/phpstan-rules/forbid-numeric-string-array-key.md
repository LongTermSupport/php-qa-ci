# `phpqaci.numericStringArrayKey` — a key that may be all digits does not stay a string

**Identifier**: `phpqaci.numericStringArrayKey` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What fires

A write of an array element, `$array[$key] = …` (or `.=`, `??=`, `++` and the other compound
writes, at any depth: `$array[$a][$b] = …`), whose key PHPStan types as a `string` that may be all
digits, when the array is taken as string-keyed:

- the array is a property whose declared type has `string` keys at the key's depth; or
- the array is a local variable, and the same function, directly or through a copy of it
  (`$copy = $array`):
  - returns it from a function whose declared return type has `string` keys at that depth;
  - passes it to a parameter whose declared type has `string` keys at that depth (a function, a
    method, a static method or a constructor, by position or by name);
  - assigns it to a property whose declared type has `string` keys at that depth; or
  - reads its keys while PHPStan types them `string`: `foreach ($array as $key => …)`,
    `array_keys()`, `array_key_first()`, `array_key_last()`, `key()`, `array_flip()`,
    `array_search()`, `array_find_key()`, `array_find()`, `array_any()`, `array_all()`,
    `array_walk()`, `array_walk_recursive()`, `uksort()`, `array_filter()` given a mode,
    `array_merge()`, `array_merge_recursive()`, or spreading it (`[...$array]`, `f(...$array)`).

The message names the array and the first place it is taken as string-keyed.

A key may be all digits when PHPStan types it a `string` that is not a `class-string`, PHPStan has
not ruled out a decimal integer, and PHPStan does not already count the int (it does for a
`numeric-string` key, typing the array `array<int|numeric-string, …>`).

## Why this is a hazard

PHP stores an array key that is a decimal integer string as an int: `$map['2024'] = 1` has the key
`2024`, the int, as do `'0'` and `'-1'` (`'01'`, `'1.5'` and `' 1'` stay strings). PHPStan types
`$map[$string] = …` as `array<string, …>` all the same, and trusts every docblock that says so. So a
map keyed by a file name, a directory, a path or any other name hands back an int for a name
written in digits, and nothing in the analysis says so:

- under `strict_types` the int throws a `TypeError` at the first `string` parameter it reaches;
- `array_merge()` and spreading renumber int keys, so the entry moves to `0`, `1`, …;
- a `string` comparison (`$key === '2024'`) is false for it.

php-qa-ci's `analysedPaths` lane crashed this way: `withUnanalysedPath('2024', '…')` stored the
path as a key, and the lane passed it to a `string` parameter whenever the directory held PHP
([#256](https://github.com/LongTermSupport/php-qa-ci/issues/256)).

## The correct construction

Declare the keys of such an array `array-key` (`int|string`), where it is stored and wherever it
is passed or returned, and cast a key to `string` where one is read. PHPStan then holds every
reader to the cast, which is the point: the type says what PHP does.

```php
// WRONG: PHPStan believes the keys are strings; '2024' comes back as 2024
/** @var array<string, string> path => reason */
private array $unanalysedPaths = [];

foreach ($this->unanalysedPaths as $path => $reason) {
    $this->report($path, $reason); // TypeError for the directory 2024
}

// RIGHT: the type admits the int, and the reader casts
/** @var array<array-key, string> path => reason */
private array $unanalysedPaths = [];

foreach ($this->unanalysedPaths as $path => $reason) {
    $this->report((string) $path, $reason);
}
```

Where the key is read nowhere (only looked up with `isset()`, `??` or `array_key_exists()`), the
declaration is all that changes. Where the map's keys are its point and are read in many places,
a list of value objects, or of `[key, value]` pairs, avoids the conversion altogether.

## What is still allowed

- A key PHPStan knows: a literal, a class constant, an enum's `->value`, any union of constant
  strings. PHPStan converts those itself.
- A `class-string`, an `int`, or a `numeric-string` key, which PHPStan types honestly.
- A key with a literal part holding a character other than a digit or `-`: `$dir . '/'`,
  `"{$dir}/{$name}"`, `'v' . $name`. Such a key cannot be a decimal integer string.
- A key that is the key variable of a `foreach` over an array around the write, which nothing in
  the loop writes (no assignment to it, destructuring into it, nested `foreach` into it, `static`,
  `global`, `unset()`, parameter of that name, call that may take it by reference, `extract()` or
  variable variable). PHP left that value as a key, and PHP leaves no all-digit string as a key, so
  it cannot become an int now. A `foreach` over a `Traversable` does not count: a generator may
  yield `'2024'` as a key.
- An array honestly typed: `array<array-key, …>`, `array<int|string, …>`, `array` (`mixed` keys) or
  a template key type.
- A local array whose keys the function never reads and that it never hands to anything declared
  string-keyed, such as a set checked only with `isset()`.
- A closure's own local array: a variable in a closure is not the enclosing function's, unless the
  closure imports it with `use`.

## Defence Before Fix record

- **Class**: an array keyed by a string that may be all digits, taken as string-keyed where PHP has
  made the key an int.
- **Hazard**: an int comes back where every type in the code, and PHPStan, says a string is, so a
  name written in digits crashes a `string` parameter under `strict_types`, is renumbered by
  `array_merge()`, or fails a `string` comparison.
- **Search, two techniques**: a text search of `src/`, `bin/` and `tests/` for every keyed array
  write `$x[$y] = …` (and `??=`, `+=`, `++`) whose key is a variable, and for the functions that
  build keyed arrays from names (`array_flip`, `array_fill_keys`, `array_combine`,
  `iterator_to_array`, `array_count_values`, `scandir`, `glob`, `json_decode`); and a reading of
  each such key's source and of the code that reads the keys back. The reading found one site that
  crashes (`QaConfigBuilder::withUnanalysedPath()` to `AnalysedPathsTool::display()`) and the latent
  ones beside it (`AnalysedPathsAudit`, `AnalysedPathsVerdictDto`, `Psr4Validator`,
  `FileReportWriter`), and found the JSON readers (`PhpstanDocsInstaller`, `SingleRuleReport`,
  `PhpstanJsonParser`, `TurboManifest`) already keep only string keys. The rule's sweep found every
  site the reading did, and more the reading had judged safe from what the key holds in practice
  (tool names, absolute paths, package names) that no type in the code bounds.
- **Instances at the sweep**: before the `foreach` narrowing, 49 in `src/` and 15 in `tests/`; with
  it, 38 in `src/` and 15 in `tests/`, none in `bin/`. The eleven it removed are keys taken unchanged from
  a `foreach` over a decoded JSON object or another array. Generated and vendored code is not swept, and the rule's own fixtures
  are not instances.
- **Narrowings**: a key with a literal non-digit part cannot be a decimal integer string. A
  `foreach` key variable over an array, unwritten in the loop, holds a key PHP made, and PHP makes
  no all-digit string key. A local array whose keys are never read and that is never handed on as
  string-keyed returns no int anywhere it is believed a string. A closure's own variable is another
  variable.
- **Next wider rule, not built**: an array whose keys come from outside the code, `json_decode()`,
  `unserialize()` or a `Traversable`, declared string-keyed. No write in the code makes the int
  there, so this rule, which reports the write, cannot see it; the defence there is the one the
  JSON readers already use, an `is_string()` check on each key read. Also not followed: an array
  handed on inside another structure (`return [$map]`, `yield $map`), by reference, or through
  `static` or `global`.
- **Runner check**: `AnalysedPathsToolTest::aDirectoryNamedInDigitsDeclaredUnanalysedPassesAndTheRunPrintsTheReason`
  pins the reported behaviour, the lane run over a project declaring `withUnanalysedPath('2024', …)`.

## Why it is opt-in

The rule cannot tell a key that is all digits in practice from one that only could be: it reports
a map keyed by tool names or by absolute paths, which no type bounds, as it reports one keyed by
directory names. Over php-qa-ci's own source that is 38 sites, most of them maps whose keys are
never digits in fact, so as a default it would fail most projects on code that works. It is
offered for the projects that key arrays by names from outside, and php-qa-ci runs it on itself.

## Implementation

- Rule: [`ForbidNumericStringArrayKeyRule`](../../src/PHPStan/Rules/ForbidNumericStringArrayKeyRule.php)
- Collector: [`NumericStringArrayKeyCollector`](../../src/PHPStan/Rules/NumericStringArrayKeyCollector.php)
