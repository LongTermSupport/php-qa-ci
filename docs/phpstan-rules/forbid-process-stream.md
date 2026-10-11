# The process's own streams stay with the entry script

**Identifier**: `phpqaci.processStream` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

Code inside a class, function or closure must not reach for the process's own standard streams:
the `STDIN`, `STDOUT` and `STDERR` constants, or a `php://stdin`, `php://stdout`, `php://stderr`
or `php://fd/N` stream opened by name with `fopen()`, `file_put_contents()`,
`file_get_contents()`, `file()`, `readfile()` (or their `Safe\` wrappers) or `new SplFileObject()`.
It takes the stream it writes to, or reads from, as a parameter, and the entry script passes the
real one.

## Why

A test calls the code directly, in the test runner's own process. What the code writes to the
process's stream goes past the runner:

- **It cannot be asserted.** `expectOutputString()` sees the output buffer, and a write to
  `STDERR` or `STDOUT` never enters it. A mutant that deletes the write survives, and nothing
  notices if the message is wrong or missing.
- **It stops mutation testing.** Infection requires the initial test run to write nothing to
  stderr, and stops PHPUnit (SIGTERM, exit 143) at the first byte. The test that writes is
  wherever random order puts it, so the run dies at a different point every time.
- **A read waits for ever.** A test that reaches `STDIN` blocks on the terminal or reads whatever
  the runner was piped.

## What is not reported

- **Script-level code**: statements outside any class, function or closure, as in a `bin/`
  entry script. It runs only as its own process, so a test can only start it as a child process,
  and captures that child's streams.
- **A PHPUnit runner extension** (a class implementing `PHPUnit\Runner\Extension\Extension`):
  the runner calls it and no test does, so its streams are the runner's own.
- **`echo` and `print`**: they go through the output buffer, which PHPUnit captures.
- **A `php://` name held as data**, such as a log target in a configuration array: only a name
  passed as the first argument to one of the opening calls above is a use.

A parameter default of `STDERR` is reported: a call that leaves the argument out reaches the
process's stream exactly as if the method named it.

## How to fix it

Take the stream as a parameter. The entry script, which owns the process, supplies the real
streams; a test supplies memory streams and asserts what was written.

```php
// WRONG — the test cannot see or assert this, and it stops Infection
final class Report
{
    public static function main(string $json): int
    {
        fwrite(STDERR, "not PHPStan JSON\n");

        return 2;
    }
}

// RIGHT — the stream is handed in
final class Report
{
    /** @param resource $stderr */
    public static function main(string $json, $stderr): int
    {
        fwrite($stderr, "not PHPStan JSON\n");

        return 2;
    }
}
```

```php
// bin/report: the entry script owns the process, so it passes the real stream
exit(Report::main(stream_get_contents(STDIN), STDERR));
```

```php
// the test hands in a memory stream and asserts the whole message
$stderr = fopen('php://memory', 'w+');
self::assertSame(2, Report::main('not json', $stderr));
rewind($stderr);
self::assertSame("not PHPStan JSON\n", stream_get_contents($stderr));
```

A class that is constructed once and writes in several places takes the stream in its
constructor instead.

## Why it is opt-in

An application that writes its CLI output through a console framework never names these
streams, but a project with its own small entry points may name them in many classes. Switching
the rule on there is a migration, so it is offered rather than imposed. php-qa-ci runs it on
itself.

## Implementation

- Rule: [`ForbidProcessStreamRule`](../../src/PHPStan/Rules/ForbidProcessStreamRule.php)
