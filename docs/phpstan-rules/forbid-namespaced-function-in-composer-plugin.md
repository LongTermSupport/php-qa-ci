# `phpqaci.composerPluginNamespacedFunction` — a Composer plugin calls only global functions and Composer's API

**Rule**: `ForbidNamespacedFunctionInComposerPluginRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A function call whose resolved name is namespaced, inside a class that is a Composer plugin:
one that implements `Composer\Plugin\PluginInterface`, or whose namespace has a `ComposerPlugin`
segment (how the plugin tree is recognised when the Composer interfaces are not loaded for
analysis). Fully qualified calls and calls through `use function` both count.

```php
namespace Acme\ComposerPlugin;

use function Safe\json_decode;

final class DeployPlugin implements PluginInterface
{
    public function deploy(Event $event): void
    {
        \Safe\exec($command, $output, $exitCode);
        $config = json_decode($contents, true);
    }
}
```

```text
Composer plugin calls namespaced function Safe\exec(): a dependency's "files" autoload is not registered when a plugin activates part-way through an install. Use a global function or Composer's own API.
```

## Why

A namespaced function is defined by a dependency's `files` autoload. Composer activates a plugin
at the moment the plugin package is installed, which on a fresh install is part-way through the
package list, and the plugin's dependencies are only autoloadable if they were installed earlier.
So whether `\Safe\exec()` resolves inside the plugin depends on the install order of unrelated
packages: the same php-qa-ci plugin passed in one project's CI and failed another's with
`Call to undefined function Safe\exec()`, and a second `composer install` in the failing project
passed because by then everything was on disk.

Rector's Safe conversion makes this easy to introduce silently: it rewrites `exec()` to
`\Safe\exec()` in any tree it runs over, including a plugin tree PHPStan is not analysing.

## How to fix

Use PHP's global functions with their `false` returns handled, or Composer's own API, which is
always loaded inside a running `composer` process:

| need              | use                                                              |
| ----------------- | ---------------------------------------------------------------- |
| run a command     | `Composer\Util\ProcessExecutor::execute()`                       |
| read a PHP config | `SplFileObject` and `PhpToken::tokenize()`                       |
| parse a version   | token checks with `strspn()`, or `Composer\Semver\VersionParser` |
| files and paths   | `Composer\Util\Filesystem`, `is_file()`, `is_dir()`              |

Functions Rector's Safe set would convert (`exec`, `preg_match`, `file_get_contents`, ...) are
best avoided in a plugin altogether, or the next writable run puts the defect back.

## Not reported

- Global functions, qualified or not: `\strlen()`, `escapeshellarg()`, `\dirname()`.
- Static and instance method calls, whatever their namespace.
- Any class that is not a Composer plugin.
