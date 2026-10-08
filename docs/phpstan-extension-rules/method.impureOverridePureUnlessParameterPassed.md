# method.impureOverridePureUnlessParameterPassed

An impure method overrides a parent method marked `@pure-unless-parameter-passed`. The parent
promises it is pure whenever the named by-reference parameter is not passed; an override that is
always impure breaks that promise for every caller that relies on it.

Reported by PHPStan itself (`MethodSignatureRule`, PHPStan 2.3.0 and later). PHPStan publishes no
page for this identifier yet, so this one stands in until it does.

## The correct construction

Keep the override free of side effects other than writing the by-reference parameter, so it
honours the inherited contract:

```php
interface Parser
{
    /** @pure-unless-parameter-passed $errors */
    public function parse(string $input, ?array &$errors = null): Ast;
}

// Reported: always impure
final class LoggingParser implements Parser
{
    /** @phpstan-impure */
    public function parse(string $input, ?array &$errors = null): Ast
    {
        error_log($input);
        // ...
    }
}

// Correct: the only side effect is the out parameter the contract names
final class StrictParser implements Parser
{
    public function parse(string $input, ?array &$errors = null): Ast
    {
        // ... fills $errors when it is passed, nothing else
    }
}
```

If implementations genuinely need side effects, the parent cannot promise conditional purity:
remove `@pure-unless-parameter-passed` from the parent method.
