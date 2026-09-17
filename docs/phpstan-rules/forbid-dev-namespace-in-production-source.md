# `phpqaci.devNamespaceInProductionSource` — dev-only code belongs under `autoload-dev`

**Rule**: `ForbidDevNamespaceInProductionSourceRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A class-like whose namespace, or whose path below its autoload root, carries a whole `Dev`
segment, while that root is declared under composer `autoload` rather than `autoload-dev`.

```php
// composer.json: "autoload": { "psr-4": { "App\\": "src/" } }
// src/Command/Dev/SandboxPushCommand.php

namespace App\Command\Dev;

final class SandboxPushCommand extends Command
{
}
```

```text
Class App\Command\Dev\SandboxPushCommand is dev-only code (a Dev segment) yet lives under the shipped composer autoload root "App\", so it installs into production and into every consumer. Move it to a src-dev/ tree mapped under autoload-dev and register it for the dev environment only.
```

The roots are resolved from the analysed project's own `composer.json`, so a project whose
shipped root is `lib/` or `app/` is read exactly as correctly; `src/` is never assumed.

## Why

The `Dev` segment and the `autoload` declaration say opposite things about the same file, and
the `autoload` one is the one that takes effect. Everything under a shipped root is installed
by `composer install --no-dev`, so maintainer tooling reaches production: its dependencies
become production dependencies, its console commands become runnable in production, and every
line of it is `composer audit` surface and attack surface the project never intended to carry.
For a library it is worse again, because the tooling lands in every consumer of the package.

Nothing fails when this happens, which is why it needs a static rule. The package is simply
larger, slower to install and more dangerous than its author believes it is, and no test can
notice.

## How to fix

Move the tree to `src-dev/` and map it under `autoload-dev`:

```json
{
    "autoload": {
        "psr-4": { "App\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "App\\Dev\\": "src-dev/" }
    }
}
```

- `src-dev/SandboxPushCommand.php` declares `namespace App\Dev;`.
- The class's tests move with it, keeping the pairing intact.
- Anything that registered the class for the runtime — a DI service definition, a console
  command list — registers it for the dev environment only, so a production container never
  tries to load a class that is not installed.
- `composer dump-autoload` afterwards, then check the class is absent from a
  `composer install --no-dev` tree.

This is the `src-dev` convention; see
[the convention in the coding standards](../coding-standards.md#dev-only-code-lives-under-autoload-dev).

## Not reported

- A `Dev` segment under an `autoload-dev` root. That is the fix, not the defect.
- Vendored code. The hazard belongs to whoever publishes the package, and the analysed
  project cannot move a file it does not own.
- A segment or class name that merely STARTS with `Dev` — `App\DevTools\Helper`,
  `App\Service\DevModeSwitch`. Only a whole segment is a declaration that the code is
  dev-only; a class ABOUT development mode is ordinary shipped code.
- A class whose short name is `Dev`. The class's own name is not part of the tree it sits in.
- Anonymous classes, which have no namespace to declare anything with.
