# `phpqaci.unvalidatedRegexPattern` — a pattern from outside compiles where a bad one is named

**Identifier**: `phpqaci.unvalidatedRegexPattern` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What fires

A call to a PCRE function, PHP's own (`preg_match`, `preg_match_all`, `preg_replace`,
`preg_replace_callback`, `preg_replace_callback_array`, `preg_filter`, `preg_split`,
`preg_grep`) or the `thecodingmachine/safe` one of the same name, whose pattern the code does
not own, unless its function installs an error handler before it and the call is inside a `try`
whose `finally` restores the handler.

The code owns a pattern that is `literal-string` typed, or built from literal strings,
`preg_quote()` results and owned local variables, joined by `.`, interpolation, a ternary or
`sprintf()`. `preg_quote()` counts only when it is given a delimiter and, where the pattern's own
delimiter can be read from its leftmost literal, that one (or the closing bracket of a bracket
pair): with none, or another, a value holding the delimiter ends the pattern early. A fragment
quoted on its own, with no delimiter beside it, is checked only for being given one. A local
variable is owned when every write to it in its function is owned; passing it to a by-reference
parameter of a call is a write it does not own, and so is passing it to any call whose callee the
rule cannot resolve, such as `$callback($pattern)`, which may take it by reference. `$alias = &$pattern` is a write to both, since a later write to either changes the other. A function that
writes a variable the rule cannot name, `$$name = …` or `extract()`, owns no local that a pattern
names. A parameter, a property, an array element, a foreach variable or any other value is not
owned.

## Why this is a hazard

PCRE does not throw on a pattern that does not compile. It raises a warning and the call returns
`false`, and `thecodingmachine/safe` turns that into `PREG_INTERNAL_ERROR: Internal error`. Neither
names the pattern or where it came from. A pattern typed on a command line, read from a
configuration file or passed in by a caller is a pattern nobody has compiled yet, so the person
who wrote it gets an error that does not tell them what to fix.

`bin/psr4-validate --help` printed exactly that: every argument was compiled as an ignore pattern,
and `--help` failed with `Unknown modifier 'h'` and `PREG_INTERNAL_ERROR`
([#192](https://github.com/LongTermSupport/php-qa-ci/issues/192)).

## The correct construction

Compile a pattern the code does not own under an error handler that throws, naming the pattern and
where it came from, and restore the handler in a `finally`:

```php
set_error_handler(static function (int $level, string $message) use ($pattern): never {
    throw new InvalidArgumentException(
        sprintf('ignore pattern "%s" is not a valid regular expression (%s)', $pattern, $message),
        $level,
    );
});
try {
    return 1 === preg_match($pattern, $subject);
} finally {
    restore_error_handler();
}
```

Put it in one place, a small class that holds the pattern and its origin, and validate when the
value enters (where a constructor or a command can still say "this argument") rather than at the
first match. php-qa-ci's own is [`RegexPattern`](../../src/Regex/RegexPattern.php).

A pattern the code does build is fine as it is; quote whatever it did not write:

```php
// WRONG: a project root containing + or ( changes what the pattern matches
preg_replace('%^' . $projectRoot . '%', '', $path);

// RIGHT: the value is matched literally
preg_replace('%^' . preg_quote($projectRoot, '%') . '%', '', $path);
```

A helper that takes a pattern only its own class passes can say so: `@param literal-string $pattern`
makes PHPStan check every caller passes a pattern the code wrote.

## What is still allowed

- A literal pattern, a class constant, a constant array of patterns, and a foreach over one: PHPStan
  types each `literal-string`.
- A pattern built in the call or in a local variable from those, from `preg_quote($value, $delimiter)`
  and from other owned locals.
- A call inside a `try` whose `finally` restores the error handler, including in its `catch`
  blocks, after the innermost function holding the call calls `set_error_handler()`: the handler
  that function installed is the one in force there. A `finally` that restores a handler the
  function never installed guards nothing, and neither does one installed in a closure, which runs
  only when it is called. A closure or class declared inside the `try` is not covered, because it
  runs whenever it is called; a closure that installs its own handler and guards its own call is.
- A first-class callable, `preg_match(...)`, which compiles nothing where it is written.

## Defence Before Fix record

- **Class**: a regular expression compiled from a value the code does not own, with nothing to
  report it by name when it does not compile.
- **Hazard**: a bad pattern from a user, a configuration file or a caller fails as an anonymous PCRE
  warning and `PREG_INTERNAL_ERROR`, so whoever wrote it cannot tell which value to fix.
- **Search, two techniques**: a text search of `src/` and `bin/` for every `preg_*` call whose first
  argument is not a quoted literal, and a reading of where each pattern found that way comes from
  (command line, configuration file, caller, or the class itself). The text search found ten calls
  in `src/`; the reading found two that take input, `Psr4Validator` (the command line and a
  project's `psr4-validate-ignore-list.txt`) and `ConfigTemplateIgnoreListAuditor` (the shipped
  ignore list), and one, `PreloadedSourceFinder`, that already compiles `infection.json`'s
  `source.excludes` under a handler that names the entry. The rule's sweep found four more the
  text search missed, because their pattern is on the line after the call or concatenates a
  parameter: `LinksChecker` (the project root, unescaped), `BundledToolVersions`,
  `ApiMustNotExposeInternalRule` and `RequireApiOrInternalTagRule` (a parameter, unescaped).
- **Instances at the sweep**: fifteen. In `src/`, ten: `Psr4Validator`,
  `ConfigTemplateIgnoreListAuditor`, `LinksChecker`, `BundledToolVersions`,
  `ApiMustNotExposeInternalRule`, `RequireApiOrInternalTagRule`, `RequireEnumOverLiteralUnionRule`
  (a parameter, unescaped), and three private helpers that take any pattern as a parameter
  (`ForbidHttpPrefixedEnvVarsRule`, `WorkflowPhpVersionDetector`, `LogArchiver`). In `tests/`, five
  helpers of the same shape in `DefenceBeforeFixDeclarationTest`, `GitHubActionsTemplatesTest` and
  `RuleDocumentationTest`. Generated and vendored code is not swept.
- **Narrowing**: a local variable every write to which is owned is owned, because what it holds at
  the call is one of those values. A call in a `try` whose `finally` restores the error handler,
  after the innermost function holding it calls `set_error_handler()`, is excluded, because it runs
  under a handler that function installed for it. The rule does not read the handler itself, so one
  that does not throw is still trusted.
- **Next wider rule, not built**: any pattern language fed a value the code does not own — `glob()`
  and `fnmatch()` as well as PCRE. Not built because the hazard is absent: neither fails to compile
  a pattern, so neither produces the anonymous error this class is about. A bad glob matches
  nothing, which is a different hazard with a different defence.
- **Runner check**: `Psr4ValidateCommandTest` pins the reported behaviour: `--help` prints the usage
  and exits 0, and an argument that does not compile fails naming it; `Psr4ValidateBinaryTest` runs
  `bin/psr4-validate` itself, from a directory with no `composer.json`. `Psr4ValidatorTest`,
  `ConfigTemplateIgnoreListAuditorTest` and `RegexPatternTest` pin the same message for the ignore
  list and the class.
- **Fix**: the two that take input compile every pattern through
  [`RegexPattern`](../../src/Regex/RegexPattern.php), as does the `psr4Validate` lane's ignore list;
  `LinksChecker`, `BundledToolVersions`, the two `@api` rules and `RequireEnumOverLiteralUnionRule`
  escape the value with `preg_quote()`; `LogArchiver` takes a matcher closure instead of a pattern;
  the helpers take a `literal-string` pattern, which PHPStan holds every caller to. The sweep now
  finds none.

## Why it is opt-in

A project whose patterns all come from its own source has nothing to report, and one that takes
patterns from its users usually has one place that does. Rather than have every consumer adopt a
helper class, the rule is offered and switched on where it fits; php-qa-ci runs it on itself.

## Implementation

- Rule: [`ForbidUnvalidatedRegexPatternRule`](../../src/PHPStan/Rules/ForbidUnvalidatedRegexPatternRule.php)
- Collectors: [`UnownedRegexPatternCollector`](../../src/PHPStan/Rules/UnownedRegexPatternCollector.php),
  [`RegexPatternVariableWriteCollector`](../../src/PHPStan/Rules/RegexPatternVariableWriteCollector.php),
  [`ErrorHandlerRestoringFinallyCollector`](../../src/PHPStan/Rules/ErrorHandlerRestoringFinallyCollector.php),
  [`ErrorHandlerInstallCollector`](../../src/PHPStan/Rules/ErrorHandlerInstallCollector.php)
- What counts as owned: [`RegexPatternOwnership`](../../src/PHPStan/Rules/RegexPatternOwnership.php)
