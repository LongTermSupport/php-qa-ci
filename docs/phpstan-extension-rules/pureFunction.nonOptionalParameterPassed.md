# pureFunction.nonOptionalParameterPassed

A function is marked `@pure-unless-parameter-passed $param`, but `$param` is required. The tag says
the function is pure whenever `$param` is not passed; a required parameter is always passed, so the
function is never pure and the tag claims something no call can satisfy.

Reported by PHPStan itself (`FunctionPurityCheck`, PHPStan 2.3.0 and later), as
`pureFunction.nonOptionalParameterPassed`, `pureMethod.nonOptionalParameterPassed` or
`purePropertyHook.nonOptionalParameterPassed` for a function, a method or a property hook. PHPStan
publishes no page for these identifiers yet, so this one stands in until it does.

## The correct construction

Make the parameter optional, so a call that omits it is the pure case the tag describes:

```php
// Reported: $errors is always passed, so the function is never pure
/** @pure-unless-parameter-passed $errors */
function parse(string $input, ?array &$errors): Ast

// Correct: a call without $errors is pure
/** @pure-unless-parameter-passed $errors */
function parse(string $input, ?array &$errors = null): Ast
```

If the parameter must stay required, the function is impure: replace the tag with
`@phpstan-impure`.
