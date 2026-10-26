# git in a test runs isolated from the host's git configuration

**Identifier**: `phpqaci.unisolatedGitProcess` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

Test code that runs git gives the process an environment that sets `GIT_CONFIG_GLOBAL` and
`GIT_CONFIG_NOSYSTEM`, and that switches off git's background maintenance (`GIT_CONFIG_COUNT` with
`GIT_CONFIG_KEY_n` entries naming `maintenance.auto` and `gc.auto`). PHPStan must be able to see
all of it in the environment. Reported, in a namespace
with a segment ending in `Tests` (`App\Tests\Git`, `App\FunctionalTests`; not `App\TestsHelper`
or `App\Contests`) or a file under the project's own
`tests/` directory (a directory named `tests` above the project root does not count):

- `new Process([...])` (Symfony) whose first command element is `git` or a path ending in `/git`,
  given no environment, `null`, or one PHPStan cannot show holds both keys;
- `Process::fromShellCommandline('git ...')`, the program being the first word that is not a
  `NAME=value` assignment;
- `proc_open()`, PHP's or `Safe\`'s, given a `git` command the same way.

The command and the environment may be passed by position or by name, and either may be held in a
variable or constant whose value PHPStan knows.

## Why

A child process inherits the environment, so git started by a test reads the global and system
configuration of whoever runs the suite. That configuration is not the code under test, and it
changes what the test observes:

- **Commit signing** (`commit.gpgsign`, `gpg.format=ssh` with an inline `user.signingkey`) makes
  `git commit` write the key to a file in the system temp directory and leave it there, which a
  temp-leak check reports against the test; with a missing key or agent the commit fails outright.
- **Hooks** (`core.hooksPath`) run the developer's own scripts inside the fixture repository.
- **`init.defaultBranch`**, **`log.showSignature`**, **`core.excludesFile`** and
  **`core.quotePath`** change branch names, `git log` output, what `git check-ignore` reports and
  how paths are printed.

CI usually has none of this, so such a test passes there and fails, or passes for the wrong
reason, on a developer's machine.

Separately, `git commit` can start a detached auto-maintenance process (`maintenance run --auto --detach`, `gc --auto`) that outlives the call and writes temporary files under `.git/objects`.
A test that deletes its fixture repository then races that process: the cleanup lists a file the
process removes before the cleanup unlinks it, and the test fails now and then. Switching
`maintenance.auto` and `gc.auto` off removes the process.

## What is not reported

- **Code outside a test.** Production code runs git on the user's own repository, where the
  user's configuration is wanted (`safe.directory`, credentials, a quote-path setting the user
  chose).
- **A command whose program PHPStan cannot read**, such as `new Process($command)` where
  `$command` is a `list<string>` of unknown content: the rule reports what it can show runs git.
- **git started indirectly**, by a script, a hook or production code the test calls. No static
  rule sees what a child process runs; the suite-wide isolation below covers it.
- **git started through any other spawner**: `exec()`, `shell_exec()`, `system()`, `passthru()`,
  `popen()` and backticks. These are not read; the suite-wide isolation covers them.
- **git that is not the first program of a shell line or command**, such as
  `cd x && git status`, `['bash', '-c', 'git status']` or git behind `env`
  (`['env', 'git', 'status']`, `env git status`): only the first program is read.
- **An environment whose isolating keys are present but unset**: Symfony Process treats a
  `false` value as removing the variable, and the rule counts the key as present.

## How to fix it

Give the process an environment that switches off both configuration files. Holding it in one
place keeps every git call in the suite the same:

```php
// WRONG — the host's signing, hooks and default branch apply
$process = new Process(['git', 'commit', '-m', 'fixture'], $cwd);

// RIGHT — an environment PHPStan can see sets both variables
final readonly class GitSandbox
{
    /** @return array{GIT_CONFIG_GLOBAL: '/dev/null', GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_COUNT: '2', GIT_CONFIG_KEY_0: 'maintenance.auto', GIT_CONFIG_VALUE_0: 'false', GIT_CONFIG_KEY_1: 'gc.auto', GIT_CONFIG_VALUE_1: '0', GIT_AUTHOR_NAME: string, GIT_AUTHOR_EMAIL: string, GIT_COMMITTER_NAME: string, GIT_COMMITTER_EMAIL: string} */
    public static function environment(): array
    {
        return [
            'GIT_CONFIG_GLOBAL'   => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_COUNT'    => '2',
            'GIT_CONFIG_KEY_0'    => 'maintenance.auto',
            'GIT_CONFIG_VALUE_0'  => 'false',
            'GIT_CONFIG_KEY_1'    => 'gc.auto',
            'GIT_CONFIG_VALUE_1'  => '0',
            'GIT_AUTHOR_NAME'     => 'Sandbox',
            'GIT_AUTHOR_EMAIL'    => 'sandbox@example.invalid',
            'GIT_COMMITTER_NAME'  => 'Sandbox',
            'GIT_COMMITTER_EMAIL' => 'sandbox@example.invalid',
        ];
    }
}

$process = new Process(['git', 'commit', '-m', 'fixture'], $cwd, GitSandbox::environment());
```

The return type is an array shape on purpose: a plain `array<string, string>` does not tell PHPStan
which keys are present, so a call given it is still reported. With the global configuration gone
there is no `user.name` either, which is why the author and committer are set too.

A script the test runs, which runs git itself, takes the same environment
(`[...GitSandbox::environment(), 'PATH' => $path]`). To cover git started by code the test calls,
set the variables for the whole test run in `phpunit.xml`:

```xml
<php>
  <env name="GIT_CONFIG_GLOBAL" value="/dev/null" force="true"/>
  <env name="GIT_CONFIG_NOSYSTEM" value="1" force="true"/>
  <env name="GIT_CONFIG_COUNT" value="2" force="true"/>
  <env name="GIT_CONFIG_KEY_0" value="maintenance.auto" force="true"/>
  <env name="GIT_CONFIG_VALUE_0" value="false" force="true"/>
  <env name="GIT_CONFIG_KEY_1" value="gc.auto" force="true"/>
  <env name="GIT_CONFIG_VALUE_1" value="0" force="true"/>
</php>
```

A test that reads the project's own checkout then also runs without the host's `safe.directory`,
which matters only when the checkout belongs to another user than the one running the tests.

## Why it is opt-in

It reads only test code, and a project whose tests never run git has nothing to fix. php-qa-ci runs
it on itself.

## Implementation

- Rule: [`ForbidUnisolatedGitProcessRule`](../../src/PHPStan/Rules/ForbidUnisolatedGitProcessRule.php)
