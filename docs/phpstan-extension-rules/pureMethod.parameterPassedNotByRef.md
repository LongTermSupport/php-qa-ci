# pureMethod.parameterPassedNotByRef

A method is marked `@pure-unless-parameter-passed $param`, but `$param` is passed by value, which
cannot make the call impure. The full explanation is on
[pureFunction.parameterPassedNotByRef](pureFunction.parameterPassedNotByRef.md).

## The correct construction

```php
// Correct: writing through &$errors is the side effect the tag makes conditional
/** @pure-unless-parameter-passed $errors */
public function parse(string $input, ?array &$errors = null): Ast
```
