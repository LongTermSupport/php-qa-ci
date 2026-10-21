# `phpqaci.numericStringArrayKey` — a key that may be all digits does not stay a string

**Identifier**: `phpqaci.numericStringArrayKey` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What fires

A write of an array element, `$array[$key] = …` (or `.=`, `??=`, `++` and the other compound
writes, at any depth: `$array[$a][$b] = …`), whose key PHPStan types as a `string` that may be all
digits, when the array is taken as string-keyed:

- the array is a property whose declared type has `string` keys at the key's depth; or
- the array is a local variable, and the same function, directly or through a copy of it
  (`$copy = $array`, or `$copy = array_filter($array)` and the other functions that keep keys,
  below):
  - returns it from a function whose declared return type has `string` keys at that depth;
  - passes it to a parameter whose declared type has `string` keys at that depth (a function, a
    method, a static method or a constructor, by position or by name);
  - assigns it to a property whose declared type has `string` keys at that depth;
  - does any of these with a literal holding it (`return [$array]` as
    `list<array<string, int>>`), at the depth the literal nests it; or
  - reads its keys while PHPStan types them `string`: `foreach ($array as $key => …)`,
    `yield from $array`, `array_keys()`, `array_key_first()`, `array_key_last()`, `key()`,
    `array_flip()`, `array_search()`, `array_find_key()`, `array_find()`, `array_any()`,
    `array_all()`, `array_walk()`, `array_walk_recursive()`, `uksort()`, `array_filter()` given a
    mode, or spreading it (`[...$array]`, `f(...$array)`); or renumbers its int keys:
    `array_merge()`, `array_merge_recursive()`, `array_splice()`, `array_shift()`,
    `array_unshift()`, and `array_reverse()` and `array_slice()` unless given `preserve_keys: true`.

A function that keeps the keys of an array it is given hands the array on: what takes its result
as string-keyed takes the array so. These are `array_filter()`, `array_replace()`,
`array_replace_recursive()`, `array_diff()`, `array_diff_key()`, `array_diff_assoc()`,
`array_intersect()`, `array_intersect_key()`, `array_intersect_assoc()`, `array_unique()`,
`array_change_key_case()`, `array_map()` given one array, and `array_reverse()` and
`array_slice()` given `preserve_keys: true`.

Keys made without an element write fire the same way, at any depth of nesting:

- an array literal's keys, including a literal spread into another (`[...$map, $name => $tool]`)
  or handed to one of the functions above (`array_merge($map, [$name => $tool])`,
  `array_replace($map, [$name => $tool])`); the message names it "The array literal";
- the keys a function makes from values, where PHPStan types them a `string` that may be all
  digits: `array_flip()`, `array_fill_keys()`, `array_combine()`, `array_count_values()`,
  `array_column()` with an index, and `iterator_to_array()`; the message names it "The result of
  array_flip()". `iterator_to_array()` over a `new FilesystemIterator`, `RecursiveDirectoryIterator`
  or `GlobIterator` keys by path name, which holds a `/`, and fires only when its flags may
  include `KEY_AS_FILENAME`; over an iterator held in a variable, whose flags cannot be seen, it
  fires.

Such keys are judged as a write into the local variable they are assigned to (`$set = array_flip($names)`), and otherwise as they stand: where they are returned, passed or assigned to
a property declared with `string` keys at their depth, or where their keys are read as above
(`foreach (array_flip($names) as $name => …)`).

An inline `@var` that asserts `string` keys that may be all digits fires as it stands when
PHPStan does not know the keys of the value it is put on to be strings at that depth, as for
`json_decode()` data or anything `mixed`: on an assignment, the value assigned; on any other
statement (an expression, `return`, `foreach` or `if`), the variable it names. PHPStan applies the
tag to the scope before the rule sees the statement, so a variable already held is judged by its
native type, which never knows its keys: an `@var` that only repeats what a docblock already told
PHPStan fires as well, and the fix is to delete it.

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
([#256](https://github.com/LongTermSupport/php-qa-ci/issues/256)). So did the `psr4` lane on a
`composer.json` PSR-4 root written `"2024"`; `PipelineBuilder::withTool()` lost a tool named
`'2024'` to the renumbering of a spread; and `phpqaci.repeatedStringLiteral` named the literal
`'2024'` as the int `2024`.

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
- Keys read where no int can misbehave:
  - `array_walk()`, `array_walk_recursive()`, `array_any()`, `array_all()` or `array_find()` given a
    closure or arrow function that takes fewer than two parameters, none of them variadic, and does
    not call `func_get_args()`, `func_get_arg()`, `compact()`, `get_defined_vars()` or `extract()`:
    the key is the second argument, and nothing takes it. A callback given any other way still fires.
  - `count(array_keys($array))`, `sizeof(array_keys($array))` and
    `array_map(strval(...), array_keys($array))` (or `'strval'` by name): the keys are counted, or
    made strings.
  - A `foreach` in a function, method or closure whose key variable the function reads only
    interpolated (`"{$key}"`), concatenated (`$key . '/'`) or cast to string (`(string) $key`),
    anywhere in its body, before, in or after the loop, where nothing in the body reads a variable
    without naming it (`compact()`, `get_defined_vars()`, `extract()`, `func_get_args()`, a function
    named at runtime, `include`, `eval`, a variable variable). An int is as good as a string in each
    of those. Any other read of the variable, `echo $key` included, still fires, as does a `foreach`
    in code outside any function.

## Defence Before Fix record

- **Class**: an array keyed by a string that may be all digits, taken as string-keyed where PHP has
  made the key an int.
- **Hazard**: an int comes back where every type in the code, and PHPStan, says a string is, so a
  name written in digits crashes a `string` parameter under `strict_types`, is renumbered by
  `array_merge()`, or fails a `string` comparison.
- **Search, two techniques** (each extension below was corroborated the same way, the text search
  extended to the key-making functions and to inline `@var` tags with `string` keys): a text
  search of `src/`, `bin/` and `tests/` for every keyed array
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
  variable. `iterator_to_array()` over a filesystem iterator made in place without
  `KEY_AS_FILENAME` keys by path name, and a path name holds a `/`. A key only counted, made a
  string, interpolated, concatenated or cast, or handed to a callback that takes no key, reaches no
  code where an int behaves otherwise than its string.
- **Keys made without an element write, added after the PR #260 verification**: the verifier
  showed the rule silent on `array_merge($this->tools, [$name => $tool])`, the `withTool()` defect
  written another way, and on keys made by `iterator_to_array()`, `array_flip()`,
  `array_fill_keys()`, `array_combine()`, `array_count_values()` and `array_column()`, and on the
  `psr4` crash shape, an `@var array<string, …>` over `json_decode()` data. Each now fires (red
  fixtures first), as do the renumbering functions besides `array_merge()`, the key-keeping
  functions, a local held in a literal, `yield from` and an inline `@var` on any statement. Run
  over the tree, the extension found three sites, all fixed: two inline `@var` tags over decoded
  JSON (`InfectionConfigSourceDirectoriesCheck`, `ManagedSourceGenerator`, the second of which
  named a `psr-4` prefix `"2024"` as an empty mapping), and one false report
  (`array_map(strval(...), array_keys(…))` in `tests/Support/`), which became a narrowing.
- **Not followed, a mechanism limit rather than an absent hazard, awaiting the Owner**: an array
  handed on by reference (`$alias = &$map`), through `static` or `global`, or as a value yielded by
  `yield $map`; a literal written into an element (`$map[$a] = [$b => …]`), whose own keys are not
  judged; keys PHPStan types `mixed` (`iterator_to_array()` over a `RecursiveIteratorIterator`,
  `(array)` of an object decoded from JSON), which no declaration asserts are strings; and a
  `Traversable` declared with `string` keys, whose keys come from code the rule does not see.
  `unserialize()` is forbidden outright by `phpqaci.dangerousFunctions`.
- **Imprecisions, awaiting the Owner**: an inline `@var` on a variable already held is judged by
  the variable's native type, because PHPStan applies the tag before the rule sees the statement
  and the node that carries the replaced type, `VarTagChangedExpressionTypeNode`, is outside
  PHPStan's backward-compatibility promise; an `@var` that only repeats a docblock's type therefore
  fires. `iterator_to_array()` over a filesystem iterator held in a variable fires, since its flags
  cannot be seen, though it keys by path name unless told otherwise.
- **Literal keys, added after the sweep**: the first version judged element writes only. Reading
  the sites found `PipelineBuilder::withTool()` building its maps as `[...$this->tools, $name => $tool]`, which lost a tool named `'2024'`, so the rule now judges array-literal keys as well. Run
  over the tree once the fixes below were in, it found no further site.
- **Fix**: all 53 sites (38 in `src/`, 15 in `tests/`) were fixed, and the rule now reports none in
  `src/`, `tests/` or `bin/`. Most were declared `array<array-key, …>` with a cast where a key is
  read as a string; `ShippedTools::all()`, an `@api` map of fixed names, writes its keys out so
  PHPStan sees they are strings. Three were real defects, each pinned by a test before its fix: the
  `analysedPaths` lane's `TypeError`, the `psr4` lane's `TypeError` on a namespace root written
  `"2024"`, and `phpqaci.repeatedStringLiteral` printing `'2024'` as `2024`. A fourth,
  `PipelineBuilder::withTool()` losing a tool named `'2024'`, was found reading the sites the rule
  reported and is what the literal-key extension covers. The others hold names no project can write
  in digits (method, class and variable names, PHPStan identifiers, paths carrying a `/` or `.php`),
  which PHPStan cannot see.
- **Runner check**: `AnalysedPathsToolTest::aDirectoryNamedInDigitsDeclaredUnanalysedPassesAndTheRunPrintsTheReason`
  pins the reported behaviour, the lane run over a project declaring `withUnanalysedPath('2024', …)`.

## Why it is opt-in

The rule cannot tell a key that is all digits in practice from one that only could be: it reports
a map keyed by tool names or by absolute paths, which no type bounds, as it reports one keyed by
directory names. Over php-qa-ci's own source that was 38 sites before they were fixed, most of
them maps whose keys are never digits in fact, so as a default it would fail most projects on code
that works. It is
offered for the projects that key arrays by names from outside, and php-qa-ci runs it on itself.

## Implementation

- Rule: [`ForbidNumericStringArrayKeyRule`](../../src/PHPStan/Rules/ForbidNumericStringArrayKeyRule.php)
- Collector: [`NumericStringArrayKeyCollector`](../../src/PHPStan/Rules/NumericStringArrayKeyCollector.php)
