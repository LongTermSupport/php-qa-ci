# `phpqaci.dangerousFunctions` — banned functions

**Rule**: `ForbidDangerousFunctionsRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A call to any of these:

| Function                                                         | Hazard                                                  |
| ---------------------------------------------------------------- | ------------------------------------------------------- |
| `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` | Command injection (OWASP A03)                           |
| `eval`                                                           | Arbitrary code execution                                |
| `unserialize`                                                    | Object injection leading to arbitrary code execution    |
| `extract`                                                        | Silent overwrite of local variables from untrusted keys |
| `parse_str` with one argument                                    | Writes variables into the local scope                   |

`parse_str` is flagged **only** when called without its second argument. With the output array
supplied it is an ordinary parsing function and does not fire.

## Why this is a hazard

The first group builds a shell command from a string. Any part of that string derived from input
becomes shell syntax, and the escaping needed to make that safe is famously easy to get subtly
wrong, especially across platforms.

`eval` and `unserialize` both turn data into behaviour. `unserialize` is the less obvious of the
two: it instantiates arbitrary classes and invokes their magic methods, so a crafted payload can
reach code that was never meant to run, using only the classes already loaded in the application.

`extract` and single-argument `parse_str` create local variables whose names come from the data.
Code below them reads variables that may or may not exist, may hold anything, and may have
overwritten something the function above was relying on.

## The correct construction

**For shell commands**, use Symfony Process, which takes an argument list and never builds a shell
string:

```php
use Symfony\Component\Process\Process;

$process = new Process(['git', 'rev-parse', 'HEAD'], cwd: $repoPath);
$process->mustRun();
$sha = trim($process->getOutput());
```

`mustRun()` throws on a non-zero exit, so a failure cannot be missed. The array form means no
escaping is required, because no shell is involved.

**For deserialisation**, use JSON:

```php
$data = json_decode($payload, associative: true, flags: \JSON_THROW_ON_ERROR);
```

`JSON_THROW_ON_ERROR` matters: without it a malformed payload returns `null` and the failure
disappears. For structured data crossing a trust boundary, decode to an array and construct a typed
object from it, so the shape is validated rather than assumed.

**Instead of `extract`**, use the array directly. Explicit access is what makes the code readable
anyway:

```php
$name = $data['name'] ?? throw new \InvalidArgumentException('name missing');
```

**Instead of single-argument `parse_str`**, pass the output array:

```php
parse_str($queryString, $params);
```

## If you believe an instance is legitimate

A deployment script running a fixed command with no interpolated input is the usual argument. Even
then Symfony Process is no harder to write, so the exemption rarely earns itself.

Where a case really is irreducible, put it in `ignoreErrors` in the project's `phpstan.neon` with a
path and this identifier, so it is visible and reviewable. Inline `@phpstan-ignore` is banned by
`phpqaci.inlinePhpstanIgnore`.

## Related

[`phpqaci.rawSql`](forbid-raw-sql.md) and
[`phpqaci.headerInjection`](forbid-header-injection.md) cover the same injection class at the
database and HTTP boundaries.
