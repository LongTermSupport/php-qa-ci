# PHP run with its output captured sends its warnings to stderr

**Identifier**: `phpqaci.phpWarningsInCapturedOutput` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

PHP run as a child process whose output is captured passes `-d display_errors=stderr` (or sets
`display_errors` to `0`, `off`, `no` or `false`) before the script. Reported:

- `new Process([...])` (Symfony) and `Process::fromShellCommandline('...')`: Symfony buffers
  what the child prints and hands it back;
- `new ProcessSpecDto([...])`, this package's process specification, whose runner returns the
  output;
- Composer's `ProcessExecutor::execute($command, $output)`, given its `$output` argument.

The command runs PHP when its program is `PHP_BINARY`, a `phpBinPath` property, `php` or a path
to a binary named `php` with an optional version (`/usr/bin/php8.5`), or a `??` or `?:` one of
whose sides is one of these. The program is the first argv element that is not spread
(`[...$wrapper, PHP_BINARY, ...]` runs PHP), or the first word of a shell line that is not a
`NAME=value` assignment, where `escapeshellarg(PHP_BINARY)` counts as PHP. The command and the
setting may be literal at the call, held in a constant PHPStan knows, or built in a local variable:
a call given a variable is judged by the last write to it before the call in the same function,
and `$command .= ' -d display_errors=stderr ...'` routes what it held.

Shell scripts are checked by the [shellCheck lane](../tools/shellCheck.md#php-output-captured-by-a-command-substitution),
which reads `$(php ...)` the same way.

## Why

The PHP CLI displays errors unless an ini says otherwise, and it displays them on **stdout**. A
warning or deprecation the child raises, from its own code, a dependency or the host's
configuration, is then written into the same stream as the value the caller wanted, and becomes
part of it: a version string reads `Deprecated: ... 8.5.1`, a JSON report no longer parses, a line a
message relays carries the warning as if it were the answer. Which hosts do this depends on their
ini, so the code works on CI and fails on a developer's machine, or the reverse.

`-d display_errors=stderr` keeps the warning visible, on the stream meant for diagnostics, and out
of the value. php-qa-ci's Turbo plugin relayed what `bin/turbo-install state` printed after a killed
install, and with `display_errors` on a warning would have appeared inside
`what the killed install left on disk:` ([#279](https://github.com/LongTermSupport/php-qa-ci/issues/279)).

## What is not reported

- **Output that is not captured**: `ProcessExecutor::execute()` without `$output` streams to the
  terminal, where a warning on stdout is only seen.
- **A command whose stderr is captured with its stdout** (`2>&1`, or a helper that joins the two):
  the warning is in the value whatever `display_errors` says, so the setting changes nothing. Such
  a value is a log, not a value to parse.
- **A command PHPStan cannot read**: a parameter, a property other than `phpBinPath`, or a
  `list<string>` of unknown content. The rule reports what it can show runs PHP; it does not guess.
- **PHP started by another spawner**: `exec()`, `shell_exec()`, `system()`, `passthru()`,
  `popen()`, `proc_open()` and backticks are reported by `phpqaci.dangerousFunctions` in production
  code, and the one use in this package's own tests joins stderr to stdout.
- **PHP behind a wrapper program**: `['nice', '-n', '19', PHP_BINARY, ...]`, `['env', 'php']` and
  `bash -c 'php ...'` name another program first, and only the first is read.
- **A variable written where the rule does not look**: by reference, by destructuring, in a
  branch the last-written line does not reflect, or in another function. The rule takes the last
  write by line, so a write in an `if` above an unconditional one is not considered.
- **A program that keeps its own warnings off stdout**: Composer installs an error handler that
  throws on a warning and writes a deprecation to stderr, so `composer` is not treated as PHP.

## How to fix it

Give PHP the setting, before the script:

```php
// WRONG — a deprecation the script raises becomes part of $output
$executor->execute(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script) . ' state', $output);

// RIGHT — warnings go to stderr, where Composer shows them, and $output is only what the script printed
$executor->execute(escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' state', $output);

new Process([\PHP_BINARY, '-d', 'display_errors=stderr', $script, '--version']);
```

Then read stdout alone: a helper that joins stderr to stdout, as `ProcessResultDto::$output` does,
puts the warning back in the value, so a value to parse comes from `ProcessResultDto::$stdout`. A
script of your own that prints a value can also set `ini_set('display_errors', 'stderr')` before it
loads anything, which covers every caller, but the caller cannot see that, so the rule still asks
for the setting at the call.

## Defence Before Fix record

**Class**: a PHP child process whose stdout is captured and used as a value while PHP's
`display_errors` can send warnings and deprecations to stdout.

**Hazard**: a warning or deprecation PHP prints, which depends on the host's ini, becomes part of a
value the code relays, parses or publishes.

**Search, technique 1 (a text search for the token)**: every place that starts PHP: `PHP_BINARY`,
`phpBinPath`, `'php'` and `$(php` / `$("$PHP...` across `src/`, `tests/`, `bin/`, `scripts/`,
`git-hooks/` and the workflow and template YAML. Found, in PHP: `PhpInvoker` (three commands, one of
them its `-r` probe read from the joined output), `PhpstanSetupKey`'s runtime probe,
`PhpStanGuardPlugin`'s `phpstan.phar --version`, and 36 places in the test suite; in shell:
`scripts/tool-install.bash:285`, `scripts/build-phar.bash:169` and `:171`,
`scripts/lib/deploy-claude-block.inc.bash:40` and `:47`, `scripts/lib/deploy-daemon-config.inc.bash:155`,
`bin/phpstan-rule:42`, and `.github/workflows/update-deps.yml:174` and `:187`.

**Search, technique 2 (reading the consumers of captured output)**: every read of
`ProcessResultDto::$stdout` and `$output`, `Process::getOutput()`, `ProcessExecutor::execute()`'s
`$output`, and every `$(...)`, backtick and redirect in the shell and the workflows, each traced back
to the program that printed it. This found what the token search could not: `bin/phpstan-rule:39`
captures `bin/qa --json` by its path, and `.github/workflows/release.yml` (and its template)
captures `changelog-release next-version` through a substitution-built path and redirects
`changelog-release pending-tags` and `notes` into files it then publishes. It confirmed that
`SkillsDeployPlugin`, the tests' `ShellRunner` and `ArkitectCheckTest` join stderr to the output,
and that the remaining consumers read git, gh or ShellCheck. A rerun of the first technique found
nothing further.

**Bound**: the rule reads Process, `ProcessSpecDto` and `ProcessExecutor` calls, inline or through
a local variable, because the originating instance built its command in a variable. The next wider
rule would follow a command through parameters, properties and other functions, and in shell
through redirects into files. It was not built: the search found no instance of the first beyond
what `PhpInvoker` centralises, which is fixed at its source, and whether a file a redirect writes is
a log or a value cannot be read from the script. The redirect instances, all of them php-qa-ci's
own `bin/` scripts, are fixed at the producer: `bin/bootstrap.php` sends `display_errors` to stderr
for every entry point that loads it.

**Pinned**: `ForbidPhpWarningsInCapturedOutputRuleTest` and `CapturedPhpOutputScannerTest` pin the
Detector; `TurboInstallPluginTest::theStateCheckRunsWithItsErrorsKeptOutOfTheLineThePluginRelays`
runs the plugin's state check under an ini that displays errors and asserts the relayed line is
only what the check printed.

## Why it is opt-in

A project that never captures a PHP child's output has nothing to fix, and one whose captured
output is only shown to a person may prefer the warning where it is. php-qa-ci runs it on itself.

## Implementation

- Rule: [`ForbidPhpWarningsInCapturedOutputRule`](../../src/PHPStan/Rules/ForbidPhpWarningsInCapturedOutputRule.php)
- Collector: [`CapturedPhpOutputCollector`](../../src/PHPStan/Rules/CapturedPhpOutputCollector.php)
- Shell: [`CapturedPhpOutputScanner`](../../src/Pipeline/Lane/ShellCheck/CapturedPhpOutputScanner.php)
