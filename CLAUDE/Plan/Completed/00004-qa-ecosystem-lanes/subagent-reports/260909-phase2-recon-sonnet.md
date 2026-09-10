# Phase 2 tool integration recon — verified facts

Fetched 2026-09-09. Raw evidence files (packagist JSON, READMEs, source excerpts) saved
alongside the original scratch copy of this report in `/workspace/untracked/scratch/`
(`A-*`, `B-*`, `C-*`, `D-*`; not tracked in git).

---

## Tool A: shipmonk/composer-dependency-analyser

1. **Latest version / PHP compat**
   - Packagist p2 (`https://repo.packagist.org/p2/shipmonk/composer-dependency-analyser.json`):
     latest stable **`1.8.4`**, released `2025-11-25T14:38:16+00:00`.
   - `require.php` = **`^7.2 || ^8.0`** — no upper bound, so **PHP 8.5 compatible**.
     README (`A-README.md`, line 8 and line 189-191) states explicitly: "✨ **Compatible:** PHP
     7.2 - 8.5" and "Runtime requires PHP 7.2 - 8.5".
   - `ext-json`, `ext-tokenizer` required. Zero Composer dependencies.

2. **PHIVE PHAR?** **No.**
   - Not present in `https://phar.io/data/repositories.xml` (grepped the full file, no
     `shipmonk`/`dependency-analyser` alias).
   - GitHub org is `shipmonk-rnd` (confirmed via GitHub search API, not `shipmonk`). Its latest
     release (tag `1.8.4`, `https://api.github.com/repos/shipmonk-rnd/composer-dependency-analyser/releases/latest`)
     has **no attached assets** — no `.phar`/`.phar.asc`.
   - Conclusion: install as a **Composer dependency** (`composer require --dev
     shipmonk/composer-dependency-analyser`), not via PHIVE/`vendor-phar/`.

3. **Executable**: `bin/composer-dependency-analyser` (from `bin` field in `composer.json`) →
   lands at `vendor/bin/composer-dependency-analyser` in a consumer project.

4. **Config file**:
   - Class: `ShipMonk\ComposerDependencyAnalyser\Config\Configuration` (namespace
     `ShipMonk\ComposerDependencyAnalyser\Config`).
   - Default filename: **`composer-dependency-analyser.php`** in cwd, auto-loaded if present.
     Custom path via `--config path/to/config.php`.
   - Minimal working example (from README, `A-README.md` lines 106-114):
     ```php
     <?php

     use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

     $config = new Configuration();

     return $config;
     ```
     (an empty `Configuration` is valid; all tuning is via fluent `->addPathToScan()`,
     `->ignoreErrors()`, etc.)

5. **Exit codes** (verified from source, `A-bin.php` + `A-ConsoleFormatter.php`):
   - Entry point `bin/composer-dependency-analyser` (fetched from
     `https://raw.githubusercontent.com/shipmonk-rnd/composer-dependency-analyser/master/bin/composer-dependency-analyser`):
     - `InvalidCliException | InvalidConfigException | InvalidPathException` → prints red error to
       stderr, **`exit(1)`**.
     - `AbortException` (e.g. `--help`/`--version` paths) → **`exit(0)`**.
     - Otherwise `exit($exitCode)` where `$exitCode` comes from the formatter.
   - `ConsoleFormatter::printResultErrors()` (src/Result/ConsoleFormatter.php line 213):
     `return $hasError ? 1 : 0;` — **1 when any dependency/usage error found, 0 when clean**.
   - **Caveat**: both "issues found" and "invalid CLI/config/path" map to exit code **1** — they
     are not distinguishable by exit code alone; only by stderr content (red "Error:" prefix for
     the latter). No distinct "crash" code exists.

6. **CLI flags** (verified from README `A-README.md` lines 82-97, cross-checked against no
   separate `--help` fetch since GitHub release had no binary to run):
   - `--composer-json path/to/composer.json` — custom path to composer.json
   - `--dump-usages symfony/console` — show usages of package(s), `*` wildcard supported
   - `--config path/to/config.php` — custom config path
   - `--version`, `--help`
   - `--verbose` — show more example classes/usages
   - `--show-all-usages`
   - `--format` — `console` (default) or `junit`
   - `--disable-ext-analysis`
   - `--ignore-unknown-classes`, `--ignore-unknown-functions`, `--ignore-shadow-deps`,
     `--ignore-unused-deps`, `--ignore-dev-in-prod-deps`, `--ignore-prod-only-in-dev-deps`
   - `NO_COLOR=1` env var disables colored output (not a flag).

---

## Tool B: tomasvotruba/type-coverage

1. **Latest version / constraints / PHP compat**
   - Packagist p2 (`https://repo.packagist.org/p2/tomasvotruba/type-coverage.json`): latest
     stable **`2.3.6`**, released `2026-08-29T08:21:40+00:00` (actively maintained — release one
     week before this recon).
   - `require.php` = **`^8.4`** → PHP 8.5 falls inside `^8.4` (>=8.4 <9.0), so **PHP 8.5
     compatible**. Note this is a break from the README's claim of "PHP 7.2+" (`B-README.md` line
     78) — that line describes an older baseline; the installed `2.3.6`'s actual `composer.json`
     constraint is `^8.4`.
   - `require.phpstan/phpstan` = **`^2.2`**.
   - Also requires `webmozart/assert: ^1.11 || ^2.1`.

2. **Configuration — exact neon keys** (verified against the package's own
   `config/extension.neon`, fetched from
   `https://raw.githubusercontent.com/TomasVotruba/type-coverage/main/config/extension.neon` —
   this is ground truth, more authoritative than the README prose):
   - Canonical (long-form) keys under `parameters.type_coverage`:
     - `return_type` (float|int, default `99`)
     - `param_type` (float|int, default `99`)
     - `property_type` (float|int, default `99`)
     - `constant_type` (float|int, default `99`) — **confirmed added**, exists as of `2.3.6`
     - `declare` (float|int, default `0`) — strict-types coverage. There is **no**
       `declare_strict_types` key; the correct key is just `declare`.
     - `print_suggestions` (bool, default `true`, marked "deprecated, yet" in the schema comment)
     - `measure` (bool, default `false`) — measure-only mode, does not fail the build
   - **Aliases** (short-form, "to avoid typos", default `null` = unset): `return`, `param`,
     `property`, `constant` — these map onto the canonical `*_type` keys. The team lead's
     assumption of `return_type`/`param_type`/`property_type` as the real keys is correct; the
     shorter `return`/`param`/`property`/`constant` forms used in the README examples
     (`B-README.md` lines 100-108, 125-127) are aliases, not the canonical schema names. Both
     forms work; **prefer the canonical `*_type`/`declare` keys** since those are what the
     `parametersSchema` documents as primary.
   - Example (canonical form):
     ```yaml
     # phpstan.neon
     parameters:
         type_coverage:
             return_type: 50
             param_type: 35.5
             property_type: 70
             constant_type: 85
             declare: 40
     ```

3. **PHPStan error identifiers**:
   - Fetched all 5 rule classes under `src/Rules/` (`ParamTypeCoverageRule`,
     `ReturnTypeCoverageRule`, `PropertyTypeCoverageRule`, `ConstantTypeCoverageRule`,
     `DeclareCoverageRule`).
   - **Only `DeclareCoverageRule` sets an explicit PHPStan error identifier**:
     `private const string IDENTIFIER = 'typeCoverage.declareCoverage';`, applied via
     `RuleErrorBuilder::message($errorMessage)->identifier(self::IDENTIFIER)`.
   - `ParamTypeCoverageRule`, `ReturnTypeCoverageRule`, `PropertyTypeCoverageRule`,
     `ConstantTypeCoverageRule` build their `RuleErrorBuilder` **without** calling
     `->identifier(...)` — they emit errors with **no explicit identifier** in this version.
     This matters for php-qa-ci's `phpstanIgnoreJustification` lane (which requires every
     `ignoreErrors` entry to carry a comment naming the identifier) — an `ignoreErrors` regex
     match on message text would be the only option for these four rules; only the declare-coverage
     rule has a stable `identifier:` you could match on in `phpstan.neon`.

4. **phpstan/extension-installer requirement**:
   - **Not a hard `require`.** It's only in the package's own `require-dev` (its dev environment),
     and it self-registers via the standard `extra.phpstan.includes` composer.json key: `"extra":
     {"phpstan": {"includes": ["config/extension.neon"]}}`.
   - If the consuming project has `phpstan/extension-installer` installed, the neon auto-includes.
   - Otherwise (as php-qa-ci already does for its other PHPStan config), the neon must be included
     manually:
     ```yaml
     includes:
         - vendor/tomasvotruba/type-coverage/config/extension.neon
     ```
   - Bundled bonus: `config/extension.neon` also includes
     `../packages/type-perfect/config/extension.neon` (the `type_perfect` rule set — a separate,
     opt-in-per-rule set of 8 rules) is pulled in automatically as part of the same package; no
     extra `composer require` needed for those.

---

## Tool C: phpcpd-next (copy/paste detector)

1. **Exact package name**: **`phpcpd-next/phpcpd`** (Packagist, confirmed 200 via
   `https://repo.packagist.org/p2/phpcpd-next/phpcpd.json`; the older candidates
   `qossmic/phpcpd`, `phpcpd/phpcpd` returned 404; `systemsdk/phpcpd` also exists as a maintained
   fork but was not the name the team asked to verify as "phpcpd-next" — see note below).
   - GitHub repo: `https://github.com/phpcpd-next/phpcpd` — "a maintained, dependency-free
     successor to the archived `sebastianbergmann/phpcpd`" (README line 9-10).
   - Latest version **`v1.4`**, released `2026-08-23T08:27:19+00:00` — actively maintained (2.5
     weeks before this recon).
   - `require.php` = **`>=8.5`** — this package **requires PHP 8.5 as a floor**, not merely
     "compatible" — it will not run on PHP 8.4 or lower. Fits php-qa-ci's `php8.5` branch exactly.
   - `ext-dom`, `ext-mbstring` required. **Zero Composer runtime dependencies** (README lines
     62-65, 96-97).
   - (Noted for completeness, not chosen: `systemsdk/phpcpd` is a separate actively-maintained
     fork targeting PHP 8.4+, also found via WebSearch — an alternative if `phpcpd-next/phpcpd`
     is ever abandoned.)

2. **PHIVE PHAR?** **No** (for `phpcpd-next/phpcpd`).
   - `phar.io/data/repositories.xml` only has one `phpcpd` alias, and it maps to the **abandoned**
     `sebastian/phpcpd` (`composer="sebastian/phpcpd"`, via `https://phar.phpunit.de/phive.xml`) —
     confirmed by grep of the full 189-line file. There is no `phpcpd-next` alias.
   - Latest GitHub release (`v1.4`,
     `https://api.github.com/repos/phpcpd-next/phpcpd/releases/latest`) was checked and its
     `assets` field is empty — **no `.phar`/`.phar.asc` attached**.
   - Conclusion: install as a **Composer dependency**: `composer require --dev
     phpcpd-next/phpcpd`. (README also documents `composer global require` and building from
     source as alternatives, but no PHAR download.)

3. **Executable**: `bin/composer-dependency-analyser`-style bin field installs the binary as
   **`phpcpd`** (drop-in name compatible with the old tool) → lands at `vendor/bin/phpcpd`.
   - Key flags (verified against README's literal `--help` dump, `C-README.md` lines 505-542):
     - `--min-lines <N>` (default: **5**)
     - `--min-tokens <N>` (default: **70**)
     - `--suffix <suffix>` (default `.php`, repeatable), `--exclude <path>` (substring or glob,
       repeatable), `--preset <name>` (e.g. `laravel`), `--no-default-excludes`
     - `--rk` (Rabin-Karp only / fast exact-match mode)
     - Output: `--log-pmd <file>` (PMD-CPD XML), `--log-json <file>` (JSON), `--log-sarif <file>`
       (SARIF 2.1.0) — **all three write to a file**, there is no "print JSON to stdout" flag.
     - `--config <file>` (default: `./phpcpd.ini` if present), `--no-config`, `--show-config`
     - `--cache`, `--cache-dir <path>`, `--incremental`
     - Orphan/dead-code mode is a separate concern (`--orphans`, `--fail-on`) — not requested by
       the team lead's questions but present and worth knowing it shares the same binary.

4. **Exit codes** (verified from README lines 115-116, 183-187 — could not fetch a `.phar` to
   verify against source since none is distributed, but the README is explicit and internally
   consistent with the "CI-ready... meaningful exit codes" feature bullet):
   - **`0`** — no clones found (clean).
   - **`1`** — clones found **or** on error (the README states both cases share code 1: "exits
     with status 1 when clones are found (or on error)"). No distinct crash code is documented.
   - Orphan mode nuance (not the default failure surface): in a default run, orphans are
     "advisory" and **do not** affect the exit code — only clone-detection sets it. Only when run
     explicitly with `--orphans` do orphans gate the exit code (same 0/1 contract).
   - **For "informational, must never fail the pipeline" wiring**: since only `0` and `1` are
     used and `1` is overloaded (findings-or-error), the php-qa-ci lane would need to **treat
     exit code 1 as informational-only** (swallow it, log the output) if it's to be wired as
     never-failing per the team's requirement — there's no separate "duplication found but tool
     ran fine" code to distinguish from a real crash. Recommend capturing stderr/stdout and
     treating any non-two-valued/unexpected exit code (i.e. not 0 or 1) as a genuine crash to
     surface, while 0/1 are both swallowed as informational.

---

## Tool D: vincentlanglet/twig-cs-fixer

1. **Latest version / constraints / PHP compat**
   - Packagist p2 (`https://repo.packagist.org/p2/vincentlanglet/twig-cs-fixer.json`): latest
     stable **`4.1.1`**, released `2026-09-08T21:26:25+00:00` — released the day before this
     recon, extremely actively maintained.
   - `require.php` = **`>=8.1`** — no upper bound, **PHP 8.5 compatible**.
   - `require.twig/twig` = **`^3.15`**.
   - Also requires `symfony/console`, `symfony/filesystem`, `symfony/finder`, `symfony/string`
     (all wide ranges spanning 5.4 through 8.0), `webmozart/assert`, `composer-runtime-api ^2.0.0`.

2. **PHIVE PHAR?** **Yes.**
   - Confirmed present in `https://phar.io/data/repositories.xml`:
     ```xml
     <phar alias="twig-cs-fixer" composer="vincentlanglet/twig-cs-fixer">
         <repository type="github" url="https://api.github.com/repos/vincentlanglet/twig-cs-fixer/releases"/>
     </phar>
     ```
   - **PHIVE alias: `twig-cs-fixer`**.
   - Latest GitHub release (tag `4.1.1`) has both assets attached:
     `twig-cs-fixer.phar` and `twig-cs-fixer.phar.asc`
     (`https://github.com/VincentLanglet/Twig-CS-Fixer/releases/download/4.1.1/twig-cs-fixer.phar`
     [+ `.phar.asc`]).
   - **Signing key**: README states (`D-README.md` line 42-43): "The PHAR files are signed with a
     public key which can be queried at `keys.openpgp.org` with the id
     **`AC0E7FD8858D80003AA88FF8DEBB71EDE9601234`**" — a full 40-hex-character fingerprint (not
     merely a short id).

3. **Executable / CLI invocation** (verified from source,
   `src/Console/Command/TwigCsFixerCommand.php`):
   - `bin` field → `vendor/bin/twig-cs-fixer`.
   - Command `const NAME = 'lint'`, with `setAliases(['check', 'fix'])`.
   - **Check-only / dry-run**: `vendor/bin/twig-cs-fixer lint /path/to/code` (or the `check`
     alias — both leave `--fix` unset).
   - **Apply fixes**: `vendor/bin/twig-cs-fixer fix /path/to/code` — the `fix` alias sets
     `$input->setOption('fix', true)` when `'fix' === $input->getFirstArgument()`; equivalently
     `vendor/bin/twig-cs-fixer lint --fix /path/to/code` sets the same flag explicitly.
   - Config override: `--config=dir/.twig-cs-fixer.php` (docs, `D-configuration.md` line 44-48).
   - Other relevant flags from `docs/configuration.md`: `--no-cache`, `--report=<format>`.

4. **Config file**: default filename(s) **`.twig-cs-fixer.php`** or **`.twig-cs-fixer.dist.php`**
   in the project root, must `return` a `TwigCsFixer\Config\Config` instance (docs,
   `D-configuration.md` lines 18-20). PHP format (a plain PHP script, same convention as
   `php_cs.php` / `rector.php` in this project). Minimal working example (docs lines 22-42):
   ```php
   <?php

   $ruleset = new TwigCsFixer\Ruleset\Ruleset();
   $ruleset->addStandard(new TwigCsFixer\Standard\TwigCsFixer());

   $config = new TwigCsFixer\Config\Config();
   $config->setRuleset($ruleset);

   return $config;
   ```

5. **Exit codes** (verified from source,
   `src/Console/Command/TwigCsFixerCommand.php::execute()`):
   - **`self::SUCCESS`** (Symfony `Command::SUCCESS` = `0`) — `0 === $report->getTotalErrors()`.
   - **`self::FAILURE`** (Symfony `Command::FAILURE` = `1`) — `$report->getTotalErrors() > 0`.
     **Important**: this is checked *after* the linter runs, including in `fix` mode — if some
     violations are non-fixable (or `allowNonFixableRules()` isn't set, which disables them by
     default) and remain after a `fix` run, the command **still exits 1** even though it modified
     files. A read-only/CI wiring must not assume `fix` always exits 0.
   - **`self::INVALID`** (Symfony `Command::INVALID` = `2`) — any `\Throwable` thrown while
     resolving config or running the linter (config errors, crashes) — caught explicitly and
     printed as `<error>Error: ...</error>` to output.
   - So the three-way contract is clean: **0 = clean, 1 = violations present, 2 = crash/config
     error** — directly usable for php-qa-ci's pass/fail/crash lane classification.

---

## Summary of what's NOT verified (be aware)

- Tool A: did not execute the tool (no sandboxed PHP/Composer install attempted in this
  read-only recon) — all CLI-flag facts come from the README, not a live `--help` run.
- Tool C: same — no `.phar` or Composer install available to run `--help` directly; relied on the
  README's literal `--help` dump, which is presented as authoritative by the project itself.
- Tool C: `systemsdk/phpcpd` was found as an alternative fork but not investigated in the same
  depth since `phpcpd-next/phpcpd` is the name that matches what the team asked about and is
  PHP-8.5-native.
