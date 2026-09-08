# PHPStan rule identifier index

**Every rule identifier php-qa-ci can print, and where its documentation is.**

PHPStan reports a failure with an identifier, not a class name:

```text
 ------ -------------------------------------------------------------------------
  42     src/Service/Importer.php
         Avoid ?? false — this hides null/undefined errors with false. Use an
         explicit null check or validate data at the API boundary.
         🪪  phpqaci.nullCoalescingFalse
 ------ -------------------------------------------------------------------------
```

`phpqaci.nullCoalescingFalse` is the only string you are given, so this index is keyed on it. Search
this page for the identifier from the failure, not for the rule's class name.

The same lookup as a command, offline and at the installed version:

```bash
vendor/bin/rule-doc phpqaci.nullCoalescingFalse
```

It prints the rule class, bundle and summary, followed by the remediation page where one exists.
An identifier this index does not carry is an error, which is the point: every identifier the
package can print must resolve here, and `tests/Small/PHPStan/RuleDocResolverTest.php` audits that.

To check whether one rule fires on one path, with the project's own configuration:

```bash
vendor/bin/phpstan-rule phpqaci.nullCoalescingFalse src/Service/Importer.php
```

Exit 0 means it did not fire; exit 1 means it did, and every location is printed. This is the
harness to reach for when writing a rule, or when a green run is suspected of not having looked.

To list every defence active in a project — WITHOUT running PHPStan — use `bin/rules`:

```bash
vendor/bin/rules [project-root] [--json]
```

It resolves the project's `phpstan.neon` (following `includes:` recursively, the project's own
override if present, else the shipped default), and prints: every rule class under `rules:` and
every `phpstan.rules.rule`-tagged service, each with its identifier, summary and doc route where
one is declared (a rule with no `IDENTIFIER` constant is still listed, marked as such rather than
dropped); the php-qa-ci pipeline's always-on lanes (`branchNamePolicy`, `packageType`,
`sensitiveParameterUsage`, `phpArkitect`); and the project record — every `ignoreErrors` entry,
with any `#` comment directly above it in the source recovered as its justification. `--json`
emits the same data structured. Exit non-zero only if the config cannot be resolved or a neon
file fails to parse.

## Always on

Loaded automatically via the PHPStan extension installer. Registered in
[`rules-default.neon`](../../rules-default.neon), which is the single source of truth.

| Identifier                                   | Rule                                       | What it requires                                                                                                    |
| -------------------------------------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| `phpqaci.dangerousFunctions`                 | `ForbidDangerousFunctionsRule`             | [No exec/eval/unserialize and similar](forbid-dangerous-functions.md)                                               |
| `phpqaci.emptyCatchBlock`                    | `ForbidEmptyCatchBlockRule`                | [A catch block must do something](forbid-empty-catch-block.md)                                                      |
| `phpqaci.missingStrictTypes`                 | `RequireDeclareStrictTypesRule`            | [`declare(strict_types=1)` in every file](require-declare-strict-types.md)                                          |
| `phpqaci.httpPrefixedEnvVars`                | `ForbidHttpPrefixedEnvVarsRule`            | [No Symfony env var named `HTTP_*`](forbid-http-prefixed-env-vars.md)                                               |
| `phpqaci.forbiddenAttribute`                 | `ForbidAllowMockWithoutExpectationsRule`   | No `#[AllowMockObjectsWithoutExpectations]`                                                                         |
| `phpqaci.newDateTime`                        | `ForbidNewDateTimeRule`                    | No direct `new DateTime` / `new DateTimeImmutable`                                                                  |
| `phpqaci.emptyLanguageConstruct`             | `ForbidEmptyLanguageConstructRule`         | No `empty()`; use an explicit type-safe check                                                                       |
| `phpqaci.looseComparison`                    | `ForbidLooseComparisonRule`                | No `==` / `!=`; use `===` / `!==`                                                                                   |
| `phpqaci.deprecatedSerializable`             | `ForbidDeprecatedSerializableRule`         | No `Serializable`; use `__serialize()` / `__unserialize()`                                                          |
| `phpqaci.nestedTernary`                      | `ForbidNestedTernaryRule`                  | No nested ternary expressions                                                                                       |
| `phpqaci.unanchoredVendorSubstringCheck`     | `ForbidUnanchoredVendorSubstringCheckRule` | [Decide ownership against the project root, not a `vendor/` substring](forbid-unanchored-vendor-substring-check.md) |
| `phpqaci.inlinePhpstanIgnore`                | `ForbidInlinePhpstanIgnoreRule`            | No inline `@phpstan-ignore`; use `ignoreErrors` in the config                                                       |
| `phpqaci.mockFinalClass`                     | `ForbidMockingFinalClassRule`              | Mock an interface, never a final class                                                                              |
| `phpqaci.ruleIdentifierMustBeConstant`       | `RequireRuleIdentifierConstantRule`        | A PHPStan rule's identifier must be a class constant                                                                |
| `phpqaci.requireSensitiveParameterAttribute` | `RequireSensitiveParameterAttributeRule`   | `#[\SensitiveParameter]` on plaintext credential parameters                                                         |
| `phpqaci.requireApiOrInternalTag`            | `RequireApiOrInternalTagRule`              | [`@api` or `@internal` on every public class-like](../tools/requireApiOrInternal.md)                                |
| `phpqaci.apiMustNotExposeInternal`           | `ApiMustNotExposeInternalRule`             | [An `@api` type must not expose an `@internal` one](../tools/requireApiOrInternal.md)                               |

## Opt in

Not loaded unless the project includes the bundle. See
[Optional Rules](../tools/phpstan.md#optional-rules) for how to enable them.

| Identifier                           | Rule                                    | Bundle                                                                          | What it requires                                                                   |
| ------------------------------------ | --------------------------------------- | ------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `phpqaci.nullCoalescingFalse`        | `ForbidNullCoalescingFalseRule`         | [generic](../../rules-optional.neon)                                            | [No `?? false`](forbid-null-coalescing-false.md)                                   |
| `phpqaci.nullCoalescingEmptyString`  | `ForbidNullCoalescingEmptyStringRule`   | [generic](../../rules-optional.neon)                                            | [No `?? ''`](forbid-null-coalescing-empty-string.md)                               |
| `phpqaci.silentCatch`                | `ForbidSilentCatchRule`                 | [generic](../../rules-optional.neon)                                            | A catch block must reference the exception it caught                               |
| `phpqaci.readonlyService`            | `RequireReadonlyServiceRule`            | [generic](../../rules-optional.neon)                                            | A service class must be `final readonly`                                           |
| `phpqaci.arrayListShouldBeVariadic`  | `RequireVariadicForSingleListParamRule` | [generic](../../rules-optional.neon)                                            | A single `@param list<T>` array parameter should be variadic                       |
| `phpqaci.enumOverLiteralUnion`       | `RequireEnumOverLiteralUnionRule`       | [generic](../../rules-optional.neon)                                            | [A docblock literal set is an undeclared enum](require-enum-over-literal-union.md) |
| `phpqaci.factorySealed`              | `FactorySealedRule`                     | [generic](../../rules-optional.neon)                                            | A factory-sealed class is constructed only by its factory                          |
| `phpqaci.deprecatedPhpunitMethod`    | `ForbidDeprecatedPhpunitMethodRule`     | [generic](../../rules-optional.neon)                                            | No PHPUnit method deprecated by the installed version                              |
| `phpqaci.headerInjection`            | `ForbidHeaderInjectionRule`             | [symfony](../../rules-optional-symfony.neon)                                    | [No raw `header()` / `setcookie()`](forbid-header-injection.md)                    |
| `phpqaci.rawSql`                     | `ForbidRawSqlRule`                      | [symfony](../../rules-optional-symfony.neon)                                    | [No concatenation in a DBAL SQL argument](forbid-raw-sql.md)                       |
| `phpqaci.cronMissingInterval`        | `RequireCronIntervalInDescriptionRule`  | [symfony](../../rules-optional-symfony.neon)                                    | A cron command must state its interval in its description                          |
| `phpqaci.requireExplicitDIAttribute` | `RequireExplicitDIAttributeRule`        | [symfony](../../rules-optional-symfony.neon)                                    | A Symfony service must declare its DI attributes explicitly                        |
| `phpqaci.conflictingDIAttributes`    | `RequireExplicitDIAttributeRule`        | [symfony](../../rules-optional-symfony.neon)                                    | A service must not carry DI attributes that contradict each other                  |
| `phpqaci.magicStringAssertion`       | `ForbidMagicStringAssertionRule`        | [none — experimental](../tools/phpstan.md#experimental-rules-not-in-any-bundle) | No magic-string assertion where an enum belongs                                    |

## Pipeline lanes

Not PHPStan rules, but lanes of `bin/qa` that print an identifier of their own. The same
`rule-doc` lookup resolves them, and the same audit requires every one to be here.

| Identifier                                          | Class                                                    | Where            | What it requires                                                                                               |
| --------------------------------------------------- | -------------------------------------------------------- | ---------------- | -------------------------------------------------------------------------------------------------------------- |
| `phpqaci.configTemplateIgnoreList`                  | `ConfigTemplateIgnoreList/ConfigTemplateIgnoreListCheck` | `bin/qa -t cti`  | [Every shipped config template is covered by the PSR-4 ignore list](../tools/configTemplateIgnoreListCheck.md) |
| `phpqaci.infectionConfigSourceDirectoriesMustExist` | `InfectionConfig/InfectionConfigSourceDirectoriesCheck`  | `bin/qa -t icsd` | [infection.json's `source.directories` resolve to real directories](../tools/infectionConfigSourceDirs.md)     |
| `phpqaci.versionPins`                               | `VersionPins/VersionPinsCheck`                           | `bin/qa -t vp`   | [phpunit.xml, safe scan-files and GitHub Actions PHP pins match the toolchain in use](../tools/versionPins.md)      |
| `phpqaci.psr4Validate`                              | `Pipeline/Lane/Psr4ValidateTool`                         | `bin/qa -t psr4` | [Every PHP file's namespace matches the composer.json autoload mapping](../tools/psr4Validate.md)                  |
| `phpqaci.packageType`                               | `Pipeline/Lane/PackageTypeTool`                          | `bin/qa -t pt`   | [composer.json declares an explicit `type`](../tools/packageType.md)                                               |
| `phpqaci.phpstanIgnoreJustification`                | `Pipeline/Lane/PhpstanIgnoreJustificationTool`           | `bin/qa -t pij`  | [Every `ignoreErrors` entry carries a usable justification](../tools/phpstanIgnoreJustification.md)                |
| `phpqaci.sensitiveParameterUsage`                   | `Pipeline/Lane/SensitiveParameterUsageTool`              | `bin/qa -t spu`  | [`#[\SensitiveParameter]` is used somewhere in src/](../tools/sensitiveParameterUsage.md)                           |
| `phpqaci.markdownLinks`                             | `Pipeline/Lane/MarkdownLinksTool`                        | `bin/qa -t ml`   | [Every link in README.md and docs/ resolves](../tools/markdownLinks.md)                                            |
| `phpqaci.phpStrictTypes`                            | `Pipeline/Lane/PhpStrictTypesTool`                       | `bin/qa -t st`   | [Every PHP file declares strict_types](../tools/phpStrictTypes.md)                                                 |
| `phpqaci.branchNamePolicy`                          | `Pipeline/Lane/BranchNamePolicyTool`                     | `bin/qa -t bnp`  | [A PR branch uses an allowed prefix, never plan/*](../../CLAUDE/branch-policy.md)                                  |
| `phpqaci.phpLint`                                   | `Pipeline/Lane/PhpLintTool`                              | `bin/qa -t lint` | [Every PHP file parses](../tools/phpLint.md)                                                                       |
| `phpqaci.composerChecks`                            | `Pipeline/Lane/ComposerChecksTool`                       | `bin/qa -t com`  | [composer.json is normalised and the autoloader dumps](../tools/composerChecks.md)                                 |
| `phpqaci.composerRequireChecker`                    | `Pipeline/Lane/ComposerRequireCheckerTool`               | `bin/qa -t cr`   | [Every symbol production code uses is a declared dependency](../tools/composerRequireChecker.md)                   |
| `phpqaci.phploc`                                    | `Pipeline/Lane/PhplocTool`                               | `bin/qa -t loc`  | [Code statistics after a green run; informational](../tools/phploc.md)                                             |
| `phpqaci.phpstan`                                   | `Pipeline/Lane/PhpstanTool`                              | `bin/qa -t stan` | [PHPStan at level max passes; the rule listing above is its detail](../tools/phpstan.md)                           |
| `phpqaci.phpArkitect`                               | `Pipeline/Lane/PhpArkitectTool`                          | `bin/qa -t arch` | [The architecture rules of the default tier hold](../tools/phpArkitect.md)                                        |
| `phpqaci.rector`                                    | `Pipeline/Lane/RectorTool`                               | `bin/qa -t r`    | [No pending Rector refactor in a read-only run](../tools/rector.md)                                                |
| `phpqaci.phpCsFixer`                                | `Pipeline/Lane/PhpCsFixerTool`                           | `bin/qa -t f`    | [No pending code-style fix in a read-only run](../tools/phpCsFixer.md)                                             |
| `phpqaci.phpunit`                                   | `Pipeline/Lane/PhpunitTool`                              | `bin/qa -t unit` | [The test suite passes, strictly, with at least one test run](../tools/phpunit.md)                                 |
| `phpqaci.infection`                                 | `Pipeline/Lane/InfectionTool`                            | `bin/qa -t infect` | [Mutation testing holds the MSI floors](../tools/infection.md)                                                   |
| `phpqaci.twigLint`                                  | `Pipeline/Lane/TwigLintTool`                             | Symfony linting  | [Every twig template compiles](../tools/twigLint.md)                                                               |
| `phpqaci.yamlLint`                                  | `Pipeline/Lane/YamlLintTool`                             | Symfony linting  | [Every yaml config file parses](../tools/yamlLint.md)                                                              |

## Why this index exists

A rule that blocks a build without explaining itself teaches nobody anything, and the explanation
has to be reachable **from the string the tool actually printed**. Documentation keyed on the rule's
class name is documentation keyed on something the practitioner was never given.

This matters more for an agent than for a person. A human can ask a colleague; an agent has the
failure text and whatever is on disk. Keeping this index in the installed package rather than only
on a website means the lookup works offline, behind a proxy, and at the version actually installed
rather than whatever the website says today.

This is [Defence Before Fix](../../CLAUDE/DefenceBeforeFix.md) applied to php-qa-ci itself.

## Adding a rule

A new rule is not finished until it appears here. In order:

1. Declare the identifier as a constant built from `RuleIdentifierInterface::PREFIX`. The
   `phpqaci.ruleIdentifierMustBeConstant` rule enforces this.
2. Write the remediation document in this directory, named for the rule in kebab-case.
3. Add the row to the correct table above.
4. Add it to [`docs/tools/phpstan.md`](../tools/phpstan.md) and correct the count stated there.

Step 4 is the one that gets skipped, and the stated count is what makes the omission visible.
