# Managed source — php-qa-ci-owned artefacts in a consumer's namespace

Some php-qa-ci features need a small PHP artefact to live in the **consumer's own
production namespace**, not in this package. The first is the **`FactorySealedBy`
attribute** read by `LTS\PHPQA\PHPStan\Rules\FactorySealedRule`: production code
annotates classes with it, so it cannot `use` it from `lts/php-qa-ci` (a
`require-dev` package — production code must never depend on a dev dependency).

Rather than have every project hand-write (and risk drifting) that artefact,
php-qa-ci **owns and generates** it into a dedicated, **locked** namespace.

## How it works

- **Target.** `ManagedSourceGenerator::resolveTarget()` reads the consumer's
  `composer.json` `autoload.psr-4` (never `autoload-dev`) and takes the first
  prefix — e.g. `Ballicom\AccountsIq\ => src/`. The managed tree is then
  `<RootNs>\PhpQaCi\` → `<srcDir>/PhpQaCi/` (e.g. `Ballicom\AccountsIq\PhpQaCi\`
  → `src/PhpQaCi/`).
- **Generation.** `ManagedSourceDeployPlugin` (a Composer plugin) regenerates the
  tree on every `composer install`/`update`. It is a no-op when php-qa-ci is the
  root package, and is suppressed by `PHP_QA_CI_DISABLE_CONFIG_PUSH=true`.
- **Shipping.** Because php-qa-ci is `require-dev`, the plugin only runs in the
  artefact-owning project's own dev/CI — so that project **commits** the generated
  files and they ship to its downstream consumers, exactly like other committed
  generated code.
- **Lock.** The tree is generated and must never be hand-edited. `bin/managed-source check` regenerates in memory and fails if the on-disk content differs
  (hand-edit / stale / missing) — wire it into the QA gate (e.g. a `qaConfig/hookPre.php`
  that runs `vendor/bin/managed-source check` through `$context->php`). A consumer may additionally block
  interactive edits with its own agent/editor guard.

## CLI

```bash
vendor/bin/managed-source generate   # (re)write the managed tree
vendor/bin/managed-source check      # fail if the tree has drifted (CI gate)
```

## Adding a managed artefact

Add an entry to `ManagedSourceGenerator::managedFiles()` returning the relative
path (under the consumer src dir) and the rendered file contents (namespace
substituted). Keep the rendered output conformant to the default CS-Fixer ruleset
so the QA pipeline never rewrites it (which would otherwise read as drift).
