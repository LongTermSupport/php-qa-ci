# `phpqaci.safeCallComparedToFalse` — a `Safe\` function throws; it never returns `false`

**Rule**: `ForbidSafeCallComparedToFalseRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A call to a function in the `Safe\` namespace ([thecodingmachine/safe](https://github.com/thecodingmachine/safe))
whose result is tested for the `false` it never returns:

```php
if (false === \Safe\realpath($path)) {
    // a dangling symlink, a missing file
    return null;
}
```

Reported:

- the call compared with `false` by `===` or `!==`, with `false` on either side, where the
  `Safe\` function's declared return type cannot be `false`;
- the call used as a condition: `if`, `elseif`, `while`, `do … while`, `for`, a ternary or short
  ternary (`?:`), `!`, `&&`, `||`, `and`, `or` and `xor`, where the declared return type is always
  truthy or is a non-empty string, so the condition can only have been asking whether the call
  failed. A loose `== false` or `!= false` is judged as a condition, as it tests the same
  truthiness: `\Safe\realpath($p) == false` is reported, `\Safe\file_get_contents($f) == false`
  is not.

The call may be fully qualified (`\Safe\realpath()`) or imported (`use function Safe\realpath;`).
It is seen through the `@` operator and through an assignment of it, in any combination:
`false === @\Safe\realpath($path)`, `if (@\Safe\realpath($path))` and
`false === ($real = @\Safe\realpath($path))` are the same call.

The type judged is the one the function declares for the call's arguments, not the call's type at
that point in the code. PHPStan narrows a repeated call after an earlier check of it, so after
`if (false === \Safe\json_decode($json)) { return; }` a second `\Safe\json_decode($json) !== false`
reads as "never `false`", though `Safe\json_decode()` can return `false`; the rule does not report
it, and the same holds for a repeated condition.

## Why this is a hazard

A native function such as `realpath()`, `fopen()` or `file_get_contents()` returns `false` on
failure. Its `Safe\` wrapper throws a `Safe\Exceptions\*` exception instead, and is typed without
the `false`. Rector's Safe set rewrites the native call into the wrapper, silently, in a writable
run. A check written for the native `false` is then dead: the comparison can never hold, the
condition can never fail, and the code throws on the very case the check exists to handle.

In [#241](https://github.com/LongTermSupport/php-qa-ci/issues/241) a guard tested `false === realpath(...)` to recognise a dangling symlink. The writable
Rector pass turned it into `\Safe\realpath(...)`, and the guard threw on every dangling symlink,
the input it was written for. PHPStan core does not always notice, and when it does
(`identical.alwaysFalse`) it does not say that the rewrite caused it.

## The correct construction

Decide which failure the code handles, and handle it the way the function reports it.

Catch the `Safe\` exception where the failure is an expected outcome:

```php
use Safe\Exceptions\FilesystemException;

try {
    $real = \Safe\realpath($path);
} catch (FilesystemException $e) {
    // a dangling symlink, a missing file
    return null;
}
```

Or use an API that reports the failure without throwing, which Rector leaves alone:

```php
$real = new \SplFileInfo($path)->getRealPath();
if (false === $real) {
    return null;
}
```

Where the failure is not expected, delete the dead check and let the exception propagate: it
carries the reason, where the `false` carried none.

## The rule's bounds

What it does not report, and why:

- a `Safe\` function whose declared return type can be `false` (`Safe\json_decode()` returns `mixed`, and
  `false` is valid JSON): the comparison tests a value the function returns;
- a condition on a `Safe\` result that can itself be falsy, such as `Safe\preg_match()`'s `0` or a
  `Safe\file_get_contents()` empty string: the condition still tests a value the function returns,
  and nothing in the code says whether it was written for the failure or for the empty value;
- a comparison with anything but `false` (`1 === \Safe\preg_match(...)`), and the native function;
- a non-`Safe\` namespaced function, whatever its name;
- a cast, `(bool) \Safe\realpath($path)`: the rule judges comparisons with `false` and conditions,
  and a cast is neither;
- a `void` `Safe\` function used as a condition, such as `if (!\Safe\mkdir($dir))`: `void` is not
  truthy, so the rule leaves it to PHPStan core, which reports it as `function.void`.
  `false === \Safe\mkdir($dir)` is reported;
- a first-class callable, such as `false === \Safe\realpath(...)` or `if (\Safe\realpath(...))`: it
  makes a `Closure` and calls nothing, so there is no result to compare or test.

The rule takes any function whose first namespace segment is `Safe` for a
[thecodingmachine/safe](https://github.com/thecodingmachine/safe) one, so a project's own
`Safe\Foo\bar()` is judged the same way. A function in a namespace that only begins with the word,
such as `SafeHelpers\realpath()`, is not.

A non-empty string is falsy only as `"0"`, which no `Safe\` function returns as a signal, so a
condition on one (`Safe\realpath()` is typed `non-empty-string`) is reported.

The next wider form is the result stored first and tested later:

```php
$real = \Safe\realpath($path);
if (false === $real) {
```

The rule sees only the call itself, under `@` or assigned inside the comparison or condition.
Following the variable needs data flow the rule does not do; PHPStan core reports the strict form as
`identical.alwaysFalse` or `notIdentical.alwaysTrue`, without naming the cause. No instance of the
stored form was found when the rule was built.
