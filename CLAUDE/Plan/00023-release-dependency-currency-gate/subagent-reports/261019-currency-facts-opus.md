# Currency facts for Plan 00023 (gathered on `origin/php8.5` at `f96d2f54`)

Sources read: `CLAUDE.md` (Releases, Tools stay current, Work on php8.5),
`CLAUDE/releases.md`, `CLAUDE/tool-currency.md`, `CLAUDE/tool-boundaries.md`,
`CLAUDE/DefenceBeforeFix.md`, `.github/workflows/{release,update-deps,ci}.yml`, `phive.xml`,
`composer.json`, `build/*/composer.{json,lock}`, `vendor-bin/shellcheck.version`,
`vendor-phar/turbo-ext.json`, `src/Changelog/BundledToolVersions.php`, `src/ShellCheck/`,
`src/Turbo/`, `scripts/tool-install.bash`, Plan 00020, issues #204 and #205.

## How far behind the repository is right now

Measured with `composer install --no-scripts --no-plugins` in the plans worktree, then
`composer outdated`, plus the GitHub "latest release" of every PHIVE and binary source.

| Source                          | Pinned                                          | Latest                             | Note                                                                                                         |
| ------------------------------- | ----------------------------------------------- | ---------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `phpunit/phpunit` (direct)      | 13.4.2                                          | 13.4.3                             | within `^13.3`                                                                                               |
| `symfony/console` (direct)      | v8.1.8                                          | v8.1.9                             | within `^8.1`                                                                                                |
| `symfony/process` (direct)      | v8.1.7                                          | v8.1.9                             | within `^8.1`                                                                                                |
| `symfony/string` (transitive)   | v8.1.7                                          | v8.1.9                             | the only transitive package behind                                                                           |
| `roave/security-advisories`     | dev-master 96cf8b7                              | dev-master 1805b1d                 | a branch pin: always "behind" by `composer outdated`; must be excluded from any gate                         |
| majors (`composer outdated -M`) | none                                            | none                               | no root dependency is a major behind today                                                                   |
| `infection.phar` (PHIVE)        | 0.35.6                                          | **0.35.8**                         | two releases unshipped; 0.35.7 is the one #204/#205 is about                                                 |
| `phpstan.phar` + Turbo manifest | 2.3.1 / `turbo-ext.json` 2.3.1                  | 2.3.1                              | current; `PharToolsVerifier` already holds the two together                                                  |
| other PHIVE PHARs (7)           | all at latest                                   | —                                  | php-cs-fixer 3.95.27, crc 4.24.0, arkitect 1.3.1, twig-cs-fixer 4.1.1, normalize 2.54.0, parallel-lint 1.4.0 |
| `build/*` (4 self-built PHARs)  | rector 2.7.0, cda 1.8.4, dcd 1.4.2, phpcpd v2.0 | `composer outdated --locked` empty | manifests require `@stable`, so no constraint can hold them                                                  |
| `vendor-bin/shellcheck`         | v0.11.0                                         | v0.11.0                            | current                                                                                                      |

## The motivating incident

- #204 (`update-deps-failure`, opened by the workflow bot on 2026-10-14) carries five further
  failure comments: the daily update has been red on every run since then.
- #205 (Owner-approved tracking issue) names the cause: Infection 0.35.7 added `logs.debugEvents`,
  which `IgnoredPathsInfectionConfig::PATH_KEYS` does not classify, so the schema-classification
  test fails on every update run. That test is a defence working as designed.
- Consequence: Infection 0.35.7 and 0.35.8 never reached a consumer, and the PHPUnit and Symfony
  patch releases above are stuck behind the same red run, because the update is all-or-nothing.
- Meanwhile `85.7.0` was released from `php8.5`. Nothing in `release.yml` asks whether the
  dependencies are current, so a release went out with the update red.

## What the release machinery does today

- `release.yml` runs on a green `CI` push to the default branch, publishes pending tags, then
  opens or refreshes `chore/release-<default>`; merging that pull request is the release. Every
  decision is a `bin/changelog-release` subcommand. It is a byte-identical copy of
  `templates/github-actions/release.yml` (`GitHubActionsTemplatesTest`), so a change to it is a
  change consumers receive.
- `ci.yml` (job name `QA Pipeline`, the one required check on `php8.5`) runs on push and
  pull_request to `php8.5`, so it runs on the release pull request.
- `update-deps.yml` runs `composer update` within constraints, `tool-install.bash update`
  (PHIVE within `phive.xml` constraints, plus ShellCheck and Turbo), `composer update` per
  `build/<tool>/` and a forced rebuild, a second root `composer update`, then full QA. It never
  raises a constraint, so a new major (or a new `0.x` minor for `^0.35`) is never taken.
- `BundledToolVersions` already reads every pinned version of a shipped tool from its pin file
  (`phive.xml installed=`, `vendor-bin/shellcheck.version`, `build/<tool>/composer.lock`). It is
  the natural "installed" side of a currency check.

## What a consumer actually receives

php-qa-ci is a library, so its root `composer.lock` is not installed by consumers. Consumers
receive: the PHARs in `vendor-phar/` (PHIVE and self-built), `turbo-ext/`, `vendor-bin/shellcheck`,
and `composer.json`'s constraints and `replace`. The root lock only decides what php-qa-ci's own
QA ran against. This matters for the transitive-dependency and constraint-floor questions.

## Shape constraints from the tool-boundaries test

A currency verdict depends on the registries at the moment it is asked, not on the commit, so
the same commit turns red when an upstream releases. As a pipeline lane run on every push and
pull request, it would turn every unrelated pull request red until the daily update lands:
exactly the coupling #204 shows is fragile. It also needs the network, which no other lane does.
Hence: a `bin/` command with a stable identifier and page, run by the release workflow and on the
release pull request, not a default lane.
