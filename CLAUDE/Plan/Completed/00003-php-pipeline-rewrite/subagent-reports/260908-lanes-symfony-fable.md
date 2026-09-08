# Subagent report: Symfony twig and yaml lint lanes

Ported `includes/symfony/twigLint.inc.bash` and `includes/symfony/yamlLint.inc.bash` to PHP.
Nothing committed. The Bash fragments are untouched.

## Files

| File | Purpose |
| --- | --- |
| `src/Pipeline/Lane/TwigLintTool.php` | `LTS\PHPQA\Pipeline\Lane\TwigLintTool`, name `twigLint`, identifier `phpqaci.twigLint` |
| `src/Pipeline/Lane/YamlLintTool.php` | `LTS\PHPQA\Pipeline\Lane\YamlLintTool`, name `yamlLint`, identifier `phpqaci.yamlLint` |
| `tests/Small/Pipeline/Lane/TwigLintToolTest.php` | 7 tests |
| `tests/Small/Pipeline/Lane/YamlLintToolTest.php` | 6 tests |
| `docs/tools/twigLint.md` | lane doc, shape of `docs/tools/phpStrictTypes.md` |
| `docs/tools/yamlLint.md` | lane doc |

## Behaviour

Both lanes return `skipped('not a Symfony project')` before doing anything when
`$config->platform` is not `PlatformEnum::Symfony`.

TwigLintTool:

1. `bin/console` (no args, `streamOutput: false`, cwd project root) via `withoutXdebug`; if the
   output lacks `lint:twig` → skipped, prints "Twig Lint not found in bin/console, skipping".
2. `<projectRoot>/vendor/symfony/twig-bundle` not a directory → skipped, prints
   "Twig Not Installed, nothing to do".
3. `bin/console lint:twig <twigDirectories...>` via `withoutXdebug`; non-zero → failed
   `Twig Lint failed (exit N)` with the identifier trailer.

YamlLintTool:

1. Filters `$config->yamlDirectories` to existing directories; none → skipped, prints the
   Bash message naming the checked directories.
2. `bin/console lint:yaml --parse-tags <existing dirs...>` via `withoutXdebug`; non-zero →
   failed `Yaml Lint failed (exit N)` with the identifier trailer.

Order of checks mirrors the Bash: twig checks the console listing before the bundle dir.

## Verification

```
composer dump-autoload -q && bin/phpunit -c qaConfig/phpunit.xml --no-coverage \
  tests/Small/Pipeline/Lane/TwigLintToolTest.php tests/Small/Pipeline/Lane/YamlLintToolTest.php
OK (13 tests, 39 assertions)
```

`CI=true QA_READONLY=1 bin/qa -t stan -p <file>` on each of the four source and test files:
`[OK] No errors` (logs in `untracked/scratch/stan-{TwigLintTool,YamlLintTool,TwigLintToolTest,YamlLintToolTest}.log`).

## Notes for the coordinator

- `ToolRegistry` already defines `twigLint` and `yamlLint` (Linting phase, no aliases, not
  path-supporting). The docs therefore give `-t twigLint` / `-t yamlLint` as the standalone
  form. Wiring into `ShippedTools` and the identifier index is left to you as instructed.
- The test harness detail worth knowing: `PhpInvoker::withoutXdebug` probes the PHP version
  before every call, so a lane that invokes the console twice needs two `'8.5.10'` answers
  queued in `FakeProcessRunner`.
