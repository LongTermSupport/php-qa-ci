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
    `array_all()`, `array_walk()`, `array_walk_recursive()`, `uksort()`, `array_rand()`,
    `array_is_list()`, `json_encode()` unless its flags hold `JSON_FORCE_OBJECT`,
    `http_build_query()` given a numeric prefix, the functions comparing keys by a callback
    (`array_diff_ukey()`, `array_diff_uassoc()`, `array_udiff_uassoc()`, `array_intersect_ukey()`,
    `array_intersect_uassoc()`, `array_uintersect_uassoc()`), `array_filter()` given a mode, or
    spreading it (`[...$array]`, `f(...$array)`); or renumbers its int keys: `array_merge()`,
    `array_merge_recursive()`, `array_multisort()`, `array_pad()`, `array_splice()`,
    `array_shift()`, `array_unshift()`, and `array_reverse()`, `array_slice()` and `array_chunk()`
    unless given `preserve_keys: true`. The `Safe\` functions of those names count as PHP's.

An array is followed wherever it goes before it is taken as string-keyed:

- into another variable, whole or at a depth: a copy, an element (`$inner = $map['a']`), a
  `foreach` value, an element written into another array (`$all[$a] = $map`, or a literal
  `$all[$a] = [$b => 1]`, whose own keys are judged at the depth they land), and back out of one;
- through an operator or a branch: `+` and `+=`, `??` and `??=`, `?:`, a ternary, a `match` arm
  and an `(array)` cast;
- through a function that keeps the keys of an array it is given, so that what takes its result
  as string-keyed takes the array so: `array_filter()`, `array_replace()`,
  `array_replace_recursive()`, every `array_diff*()`, `array_udiff*()`, `array_intersect*()` and
  `array_uintersect*()` function (the first array's keys), `array_unique()`, `array_change_key_case()`, `array_map()` given one array,
  `array_reverse()` and `array_slice()` given `preserve_keys: true`, `array_chunk()` given it (one
  level down), `new ArrayObject()` and `new ArrayIterator()` (and `getArrayCopy()` back), and a
  function or method whose parameter's key type is a template it returns;
- by reference: `$alias = &$map`, a `foreach` by reference, a reference to a property, and a
  by-reference parameter declared string-keyed, which hands the array back to its caller;
- through `static`, through `global` or `$GLOBALS` and code outside any function, which every
  function that names the global shares, and out of a generator, as a value it yields or the
  value it returns, against the `Generator`'s declared value or return type.

Keys made without an element write fire the same way, at any depth of nesting:

- an array literal's keys, including a literal spread into another (`[...$map, $name => $tool]`)
  or handed to one of the functions above (`array_merge($map, [$name => $tool])`,
  `array_replace($map, [$name => $tool])`); the message names it "The array literal";
- the keys a function makes from values, where PHPStan types them a `string` that may be all
  digits: `array_flip()`, `array_fill_keys()`, `array_combine()`, `array_count_values()`,
  `array_column()` with an index, and `iterator_to_array()`; the message names it "The result of
  array_flip()". `iterator_to_array()` over a `new FilesystemIterator`, `RecursiveDirectoryIterator`
  or `GlobIterator` keys by path name, which holds a `/`, and fires only when its flags may
  include `KEY_AS_FILENAME`; over an iterator held in a variable, it fires when any write of the
  variable that may reach the call made such an iterator, or one whose flags it cannot see.

Such keys are judged as a write into the local variable they are assigned to (`$set = array_flip($names)`), and otherwise as they stand: where they are returned, passed or assigned to
a property declared with `string` keys at their depth, or where their keys are read as above
(`foreach (array_flip($names) as $name => …)`).

An inline `@var` that asserts `string` keys that may be all digits fires when PHPStan does not
know the keys it is put on to be strings at that depth, as for `json_decode()` data or anything
`mixed`:

- on a variable the statement binds (an assignment's target, a `foreach` value, a destructured
  element), the value the statement gives it;
- on a variable already held (on an expression, `return`, `foreach` or `if`), every write of the
  variable that may reach the tag, in the function, a loop's later writes included. It fires when
  any one of them was not typed string-keyed at that depth. A write stops reaching the tag once a
  later statement before it overwrites the variable on every path that goes on: an assignment, an
  `if` with an `else` whose every branch writes it or leaves, a `try` whose body and every catch
  that goes on write it (or whose `finally` does), and a `switch` with a `default` whose every case,
  entered at that case, writes it or leaves before a `break` or `continue` takes it to the statement
  after the `switch`. A `break 2` or `continue 2` (or higher) that leaves the loop around the
  `switch` as well leaves, like a `return`. A call that takes the variable by reference writes it
  with the type PHPStan gives what the call writes back: a sort that keeps keys (`ksort()`,
  `asort()`, `uasort()` and the rest) keeps them, `sort()`, `rsort()`, `usort()` and `shuffle()`
  make a list, `array_push()` and `array_unshift()` add int keys, and a parameter of a function or
  method declared with `string` keys (or a `@param-out` saying so) writes string keys. A call in an
  arrow function, or in a closure that imports the variable with `use` and no `&`, works on that
  function's own copy, so it is not a write of the variable in the enclosing function; `use (&$map)`
  shares the variable, and a call there is. An `@var` that only restates what PHPStan already knew
  does not fire.

The message names the array and the first place it is taken as string-keyed.

A key may be all digits when PHPStan types it a `string` that is not a `class-string`, PHPStan has
not ruled out a decimal integer (`non-decimal-int-string` does), and PHPStan does not already count the int (it does for a
`numeric-string` key, typing the array `array<int|numeric-string, …>`).

## Why this is a hazard

PHP stores an array key that is a decimal integer string as an int: `$map['2024'] = 1` has the key
`2024`, the int, as do `'0'` and `'-1'` (`'01'`, `'1.5'` and `' 1'` stay strings). PHPStan types
`$map[$string] = …` as `array<string, …>` all the same, and trusts every docblock that says so. So a
map keyed by a file name, a directory, a path or any other name hands back an int for a name
written in digits, and nothing in the analysis says so:

- under `strict_types` the int throws a `TypeError` at the first `string` parameter it reaches;
- `array_merge()` and spreading renumber int keys, so the entry moves to `0`, `1`, …;
- `json_encode()` writes an array whose keys are `0`, `1`, … in order as a JSON list, so
  `json_encode(['0' => 'a'])` gives `["a"]`, not `{"0":"a"}`, and the reader of the JSON loses the
  name; `array_is_list()` says `true` of it for the same reason;
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
- A key declared `non-decimal-int-string` (`@param non-decimal-int-string $key`, or the same type
  on a `@var`): PHPStan knows the string is never a decimal integer, so PHP never stores it as an
  int. Use it where a name is guaranteed to carry a non-digit, such as one validated by a pattern.
  The other string refinements (`non-empty-string`, `non-falsy-string`, `lowercase-string`,
  `non-numeric-string`) do not rule a decimal integer out as far as PHPStan can tell, and still fire.
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
- A local declared `global` only by name is its own variable in every function that does not.
- Keys a generator yields (`yield $name => 1`): a generator hands its keys on as they are, and only
  an array turns `'2024'` into `2024`. `iterator_to_array()` over it is judged above.
- `json_encode()` given `JSON_FORCE_OBJECT`, `http_build_query()` without a numeric prefix and
  `array_chunk()` without `preserve_keys: true` (which renumbers each chunk): an int key comes out
  as its string would.
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
  `array_merge()`, turns a JSON object into a list, or fails a `string` comparison.
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
- **Full width, added after the second PR #260 review**: the shapes the earlier version left
  unfollowed now fire, each from a red fixture first: arrays handed on by reference, through
  `static`, `global`, `$GLOBALS` and code outside any function, out of a generator, and into and out
  of an element; keys through an operator or a branch; the readers `array_pad()`,
  `array_multisort()`, `json_encode()`, `array_is_list()`, `array_rand()`, `http_build_query()` with
  a prefix and the callback-comparing `array_*_ukey()`/`*_uassoc()` functions; and the keepers
  `array_udiff*()`/`array_uintersect*()`, `array_chunk()` with `preserve_keys`, `ArrayObject`,
  `ArrayIterator` and a callee generic in its keys. An inline `@var` on a variable already held is
  judged against the writes that may reach it, read from the `VariableWritesNode` PHPStan hands a
  collector for each function body, so an `@var` that only restates known keys no longer fires; an
  iterator held in a variable is judged by the flags of the writes that reach the read.
  Run over the tree, the widening found five sites, none a live defect (each key a path holding a
  `/` or a PHPStan identifier), each declared `array-key`: `TemporaryDirectory`'s leftovers and
  the test reading them, `ActiveRulesLister`'s seen set, `PhpstanDocsInstallerTest`'s identifiers
  and `InfectionTool::inSourceScope()`'s paths, which also cast each key back to a string.
  `unserialize()` is forbidden outright by `phpqaci.dangerousFunctions`.
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

## Limits

- Keys PHPStan types `mixed` (`iterator_to_array()` over a `RecursiveIteratorIterator`, `(array)` of
  an object decoded from JSON) are not judged: no declaration asserts they are strings, and at
  level max PHPStan itself reports a `mixed` value reaching a declared type.
- PHPStan types a variable named by `global` as `mixed`, so a `foreach` over it reads no string
  keys; a global is taken as string-keyed only where it is handed to a declaration.
- A variable that may be aliased is written where PHPStan cannot follow it, so an inline `@var` on
  it fires whatever was written: declare the keys `array-key` instead. It may be aliased when it is
  either side of `=&`, a reference in an array literal or a destructuring (or what such a literal
  is assigned to), a `foreach` value by reference, imported into a closure by reference, a
  parameter taken by reference or promoted, `global` or `static`, or anything at all in a function
  that returns by reference.
- A call that takes a variable by reference is judged by what PHPStan writes back, as above. Where
  that is not known, the write is one of keys PHPStan does not know, and an inline `@var` on the
  variable after it fires: a parameter typed `mixed` or not typed at all, which PHPStan writes back
  as that type; a callee the rule cannot name (a callable held in a variable, `new $class()`); and
  a variable spread into the call. PHPStan's own per-function writers (`preg_match()`'s matches,
  `parse_str()`'s result) are not consulted; such a write is judged by the parameter's declared out
  type, which for those functions holds no `string` keys either. Declare the parameter's type, or
  its `@param-out`, with the keys it writes.
- The overwrites that stop an earlier write reaching an inline `@var` are those listed above. An
  overwrite in any other shape (a `do … while` body, a loop that always runs) leaves the earlier
  write reaching the tag, which then fires if that write's keys were not known.
- A filesystem iterator held in a variable that is also a parameter, or whose flags are changed
  by a call (`setFlags()`), may key by file name, and `iterator_to_array()` over it fires.

## Why it is opt-in

The rule cannot tell a key that is all digits in practice from one that only could be: it reports
a map keyed by tool names or by absolute paths, which no type bounds, as it reports one keyed by
directory names. Over php-qa-ci's own source that was 38 sites before they were fixed, most of
them maps whose keys are never digits in fact, so as a default it would fail most projects on code
that works. It is
offered for the projects that key arrays by names from outside, and php-qa-ci runs it on itself.

## Implementation

- Rule: [`ForbidNumericStringArrayKeyRule`](../../src/PHPStan/Rules/ForbidNumericStringArrayKeyRule.php)
- Collectors: [`NumericStringArrayKeyCollector`](../../src/PHPStan/Rules/NumericStringArrayKeyCollector.php),
  [`NumericStringArrayKeyWritesCollector`](../../src/PHPStan/Rules/NumericStringArrayKeyWritesCollector.php)
  and [`ArrowFunctionSpanCollector`](../../src/PHPStan/Rules/ArrowFunctionSpanCollector.php)
