# pureFunction.parameterPassedNotByRef

A function is marked `@pure-unless-parameter-passed $param`, but `$param` is passed by value.
Passing a value cannot by itself cause a side effect, so any impurity would be in the body and
would not depend on the argument: the condition the tag states is never the reason the function is
impure. Only a by-reference out parameter makes the conditional verdict meaningful.

Reported by PHPStan itself (`FunctionPurityCheck`, PHPStan 2.3.0 and later), as
`pureFunction.parameterPassedNotByRef`, `pureMethod.parameterPassedNotByRef` or
`purePropertyHook.parameterPassedNotByRef` for a function, a method or a property hook. PHPStan
publishes no page for these identifiers yet, so this one stands in until it does.

## The correct construction

Name a by-reference out parameter in the tag:

```php
// Reported: a by-value $verbose cannot make the call impure
/** @pure-unless-parameter-passed $verbose */
function parse(string $input, bool $verbose = false): Ast

// Correct: writing through &$errors is the side effect the tag makes conditional
/** @pure-unless-parameter-passed $errors */
function parse(string $input, ?array &$errors = null): Ast
```

If no by-reference parameter is involved, the tag does not apply: mark the function
`@phpstan-pure` when its body has no side effects, or `@phpstan-impure` when it has.
