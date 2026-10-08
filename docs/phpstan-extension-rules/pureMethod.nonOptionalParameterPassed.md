# pureMethod.nonOptionalParameterPassed

A method is marked `@pure-unless-parameter-passed $param`, but `$param` is required, so the method
is never pure. The full explanation is on
[pureFunction.nonOptionalParameterPassed](pureFunction.nonOptionalParameterPassed.md).

## The correct construction

```php
// Correct: a call without $errors is pure
/** @pure-unless-parameter-passed $errors */
public function parse(string $input, ?array &$errors = null): Ast
```
