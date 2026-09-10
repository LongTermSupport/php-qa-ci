# Debug output functions are banned when they print

**Identifier**: `phpqaci.debugOutputFunction` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

`var_dump()`, `print_r()` and `var_export()` must not be left in place printing.

Almost every one of these is a debugging session someone forgot to remove. Left in, they write
internal state into whatever the output stream happens to be — an HTML response, a JSON body
that is now malformed, a log line, a CLI report — and what they write is exactly the sort of
thing that should not be there: object graphs, configuration, connection details, tokens.

## The exemption, which is the point of the rule

`print_r()` and `var_export()` take a second argument that makes them **return a string instead
of printing it**:

```php
$message = var_export($config, true);   // fine: returns, does not print
$logger->debug('resolved config: ' . $message);
```

That is a legitimate and quite normal way to render a value into a message, so it is not
reported. `var_dump()` has no such form and is always reported.

This exemption is why the check is a rule rather than a grep: the function name alone does not
tell you whether the call is a mistake.

## How to fix it

```php
// WRONG — prints into the response
var_dump($order);
print_r($order);

// RIGHT — render, then send it somewhere that is meant to receive it
$logger->debug('order state', ['order' => $order]);
$logger->debug(print_r($order, true));
```

If you want the value on screen during development, use the debugger or a logger with a console
handler. Neither survives into production by accident.

## Why it is opt-in

A CLI tool whose *job* is printing structures — a dumper, a config inspector, a report
generator — uses these deliberately. Rather than have such a project carry per-call
suppressions, the rule is offered and switched on where it fits.

## Provenance

Lifted from
[spaze/phpstan-disallowed-calls](https://github.com/spaze/phpstan-disallowed-calls)
(MIT, Copyright (c) 2018 Michal Špaček), which bans these in its dangerous-calls bundle with the
same second-argument exemption. Carried here rather than importing the extension, so a single
ruleset owns the convention and two engines cannot drift.

## Implementation

- Rule: [`ForbidDebugOutputFunctionsRule`](../../src/PHPStan/Rules/ForbidDebugOutputFunctionsRule.php)
