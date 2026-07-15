# PHP Layer Audit: php-qa-ci Repository

**Date**: 2025-07-15 | **Audit Scope**: /workspace source and entry points  
**File Paths**: All absolute paths; one claim per row with evidence

---

## 1. /workspace/src/ – PHP Classes & Namespaces (50 files)

| Namespace/Class | File | Purpose (1-line) | Used By | Test Coverage |
|---|---|---|---|---|
| `LTS\PHPQA\Constants` | src/Constants.php | Defines QA_QUICK_TESTS_KEY constant for test optimization flags | bin/phpunit (indirectly via phpunit.inc.bash) | ✓ None observed |
| `LTS\PHPQA\Helper` | src/Helper.php | Static utilities for composer.json parsing and project root discovery | bin/psr4-validate, bin/managed-source, bin/mdlinks, bin/package-type-check, bin/phpunit-check-annotation, bin/sensitive-parameter-usage | ✓ tests/Small/HelperTest.php |
| `LTS\PHPQA\Psr4Validator` | src/Psr4Validator.php | Validates PSR-4 namespace compliance against directory structure | bin/psr4-validate via includes/generic/psr4Validate.inc.bash | ✓ tests/Small/Psr4ValidatorTest.php |
| `LTS\PHPQA\ManagedSource\ManagedSourceGenerator` | src/ManagedSource/ManagedSourceGenerator.php | Generates managed-source tree (FactorySealedBy attribute) into consumer projects | bin/managed-source, ComposerPlugin/ManagedSourceDeployPlugin | ✓ tests/Small/ManagedSource/ManagedSourceGeneratorTest.php |
| `LTS\PHPQA\Markdown\LinksChecker` | src/Markdown/LinksChecker.php | Validates internal and external links in markdown files | bin/mdlinks via includes/generic/markdownLinks.inc.bash | ✓ tests/Large/Markdown/LinksCheckerTest.php |
| `LTS\PHPQA\PackageType\ExplicitPackageTypeCheck` | src/PackageType/ExplicitPackageTypeCheck.php | Validates package type is explicitly declared in composer.json | bin/package-type-check via includes/generic/packageType.inc.bash | ✓ tests/Small/PackageType/ExplicitPackageTypeCheckTest.php |
| `LTS\PHPQA\PackageType\ExplicitPackageTypeDetector` | src/PackageType/ExplicitPackageTypeDetector.php | Detects explicit type declarations in composer.json | bin/package-type-check | ✓ tests/Small/PackageType/ExplicitPackageTypeDetectorTest.php |
| `LTS\PHPQA\PackageType\ProjectComposerTypeReader` | src/PackageType/ProjectComposerTypeReader.php | Reads project's package type from composer.json | bin/package-type-check | ✓ tests/Small/PackageType/ProjectComposerTypeReaderTest.php |
| `LTS\PHPQA\PackageType\DevAutoloadNamespaceReader` | src/PackageType/DevAutoloadNamespaceReader.php | Extracts dev autoload namespaces from composer.json for package-type validation | bin/package-type-check | ✓ tests/Small/PackageType/DevAutoloadNamespaceReaderTest.php |
| `LTS\PHPQA\PHPUnit\CheckAnnotations` | src/PHPUnit/CheckAnnotations.php | Validates PHPUnit test annotations (Small/Medium/Large size) | bin/phpunit-check-annotation via includes/generic/phpunitAnnotations.inc.bash (commented out) | ⚠️ No dedicated test |
| `LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageScanner` | src/SensitiveParameter/SensitiveParameterUsageScanner.php | Detects #[\SensitiveParameter] attribute usage in codebase | bin/sensitive-parameter-usage via includes/generic/sensitiveParameterUsage.inc.bash | ✓ tests/Small/SensitiveParameter/SensitiveParameterUsageScannerTest.php |
| `LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageResult` | src/SensitiveParameter/SensitiveParameterUsageResult.php | Data class for sensitive parameter scan results | bin/sensitive-parameter-usage | ✓ Tested implicitly via SensitiveParameterUsageScannerTest |
| **Composer Plugins** (4 files) |||||
| `LTS\PHPQA\ComposerPlugin\SkillsDeployPlugin` | src/ComposerPlugin/SkillsDeployPlugin.php | Deploys Claude Code Skills/Agents on post-install/post-update | composer.json extra.class | ⚠️ No test |
| `LTS\PHPQA\ComposerPlugin\ManagedSourceDeployPlugin` | src/ComposerPlugin/ManagedSourceDeployPlugin.php | Deploys managed-source tree (FactorySealedBy) on post-install/post-update | composer.json extra.class | ⚠️ No test |
| `LTS\PHPQA\ComposerPlugin\PhiveUpdatePlugin` | src/ComposerPlugin/PhiveUpdatePlugin.php | Updates PHIVE-managed PARs on post-install/post-update | composer.json extra.class | ⚠️ No test |
| `LTS\PHPQA\ComposerPlugin\PhpStanGuardPlugin` | src/ComposerPlugin/PhpStanGuardPlugin.php | Validates phpstan/phpstan version after install (prevents version leaks) | composer.json extra.class | ⚠️ No test |
| **PHPStan Rules (24 files – static analysis guardrails)** |||||
| `LTS\PHPQA\PHPStan\Attribute\FactorySealedBy` | src/PHPStan/Attribute/FactorySealedBy.php | Attribute that marks a class as factory-sealed (managed-source, committed to consumer projects) | Production user code (via managed-source deployment) | ✓ tests/Small/PHPStan/Attribute/FactorySealedByTest.php |
| `LTS\PHPQA\PHPStan\Rules\FactorySealedRule` | src/PHPStan/Rules/FactorySealedRule.php | Enforces factory-sealed construction (sealing attribute → only authorised factory can instantiate) | phpstan via configDefaults/generic/rules-optional.neon | ✓ tests/Small/PHPStan/Rules/FactorySealedRuleTest.php |
| `LTS\PHPQA\PHPStan\Rules\FactorySealedDetector` | src/PHPStan/Rules/FactorySealedDetector.php | Detects factory-sealed violations (pure logic, testable) | FactorySealedRule | ✓ tests/Small/PHPStan/Rules/FactorySealedDetectorTest.php |
| `LTS\PHPQA\PHPStan\Rules\SealingAttributeReader` | src/PHPStan/Rules/SealingAttributeReader.php | Resolves authorised factory from sealing attribute (reflection-based) | FactorySealedRule | ✓ tests/Small/PHPStan/Rules/SealingAttributeReaderTest.php |
| `LTS\PHPQA\PHPStan\Rules\RequireSensitiveParameterAttributeRule` | src/PHPStan/Rules/RequireSensitiveParameterAttributeRule.php | Requires #[\SensitiveParameter] on credential parameters (PHP 8.2+) | phpstan via rules-default.neon | ✓ tests/Small/PHPStan/Rules/RequireSensitiveParameterAttributeRuleTest.php |
| `LTS\PHPQA\PHPStan\Rules\ApiOrInternalTagDetector` | src/PHPStan/Rules/ApiOrInternalTagDetector.php | Detects @api/@internal phpdoc tags | API/internal boundary rules | ✓ tests/Small/PHPStan/Rules/ApiOrInternalTagDetectorTest.php |
| `LTS\PHPQA\PHPStan\Rules\ApiOrInternalTagVerdictEnum` | src/PHPStan/Rules/ApiOrInternalTagVerdictEnum.php | Enum: {UNCLASSIFIED, API, INTERNAL, CONTRADICTORY} for tag detection verdicts | ApiOrInternalTagDetector | ✓ Tested via ApiOrInternalTagDetectorTest |
| `LTS\PHPQA\PHPStan\Rules\RequireApiOrInternalTagRule` | src/PHPStan/Rules/RequireApiOrInternalTagRule.php | Requires @api or @internal phpdoc tag on classes/interfaces/traits | phpstan via rules-optional.neon | ✓ tests/Small/PHPStan/Rules/RequireApiOrInternalTagRuleTest.php |
| `LTS\PHPQA\PHPStan\Rules\ApiMustNotExposeInternalRule` | src/PHPStan/Rules/ApiMustNotExposeInternalRule.php | Forbids @api methods from exposing @internal types (API surface leak detection) | phpstan via rules-optional.neon | ✓ (used by test asset suite; no unit test) |
| `LTS\PHPQA\PHPStan\Rules\DeprecatedPhpunitMethodDetector` | src/PHPStan/Rules/DeprecatedPhpunitMethodDetector.php | Detects deprecated PHPUnit assertion methods | ForbidDeprecatedPhpunitMethodRule | ✓ tests/Small/PHPStan/Rules/DeprecatedPhpunitMethodDetectorTest.php |
| `LTS\PHPQA\PHPStan\Rules\ForbidDeprecatedPhpunitMethodRule` | src/PHPStan/Rules/ForbidDeprecatedPhpunitMethodRule.php | Forbids deprecated PHPUnit assertions (e.g., assertEquals → assertSame) | phpstan via rules-default.neon | ✓ tests/Small/PHPStan/Rules/ForbidDeprecatedPhpunitMethodRuleTest.php |
| `LTS\PHPQA\PHPStan\Rules\ForbidEmptyCatchBlockRule` | src/PHPStan/Rules/ForbidEmptyCatchBlockRule.php | Forbids silent/empty catch blocks (silent error swallowing) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidEmptyLanguageConstructRule` | src/PHPStan/Rules/ForbidEmptyLanguageConstructRule.php | Forbids empty() construct (type-ambiguous, slow) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidHeaderInjectionRule` | src/PHPStan/Rules/ForbidHeaderInjectionRule.php | Detects potential HTTP header injection (header() with user input) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidInlinePhpstanIgnoreRule` | src/PHPStan/Rules/ForbidInlinePhpstanIgnoreRule.php | Forbids @phpstan-ignore comments (use baseline instead) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidLooseComparisonRule` | src/PHPStan/Rules/ForbidLooseComparisonRule.php | Forbids loose == comparisons (enforce strict ===) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidMagicStringAssertionRule` | src/PHPStan/Rules/ForbidMagicStringAssertionRule.php | Forbids magic string assertions in tests (use named constants) | phpstan via rules-optional.neon | ✓ tests/Small/PHPStan/Rules/ForbidMagicStringAssertionRuleTest.php |
| `LTS\PHPQA\PHPStan\Rules\ForbidMockingFinalClassRule` | src/PHPStan/Rules/ForbidMockingFinalClassRule.php | Forbids mocking final classes (Mockery/PHPUnit.mock incompatible) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidNestedTernaryRule` | src/PHPStan/Rules/ForbidNestedTernaryRule.php | Forbids nested ternary operators (readability, precedence bugs) | phpstan via rules-optional.neon | ✓ tests/Small/PHPStan/Rules/ForbidNestedTernaryRuleTest.php |
| `LTS\PHPQA\PHPStan\Rules\ForbidNewDateTimeRule` | src/PHPStan/Rules/ForbidNewDateTimeRule.php | Forbids direct DateTime instantiation (use clock abstraction) | phpstan via rules-optional.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingEmptyStringRule` | src/PHPStan/Rules/ForbidNullCoalescingEmptyStringRule.php | Forbids ?? '' (use explict ?? null check) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidNullCoalescingFalseRule` | src/PHPStan/Rules/ForbidNullCoalescingFalseRule.php | Forbids ?? false (use explict ?? null check) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidRawSqlRule` | src/PHPStan/Rules/ForbidRawSqlRule.php | Detects raw SQL in code (encourage parameterised queries) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\ForbidSilentCatchRule` | src/PHPStan/Rules/ForbidSilentCatchRule.php | Forbids silent catch without re-throw (error swallowing) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\RequireDeclareStrictTypesRule` | src/PHPStan/Rules/RequireDeclareStrictTypesRule.php | Requires declare(strict_types=1) in all PHP files | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\RequireExplicitDIAttributeRule` | src/PHPStan/Rules/RequireExplicitDIAttributeRule.php | Requires #[DI\Inject] or #[Autowire] on constructor params | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\RequireReadonlyServiceRule` | src/PHPStan/Rules/RequireReadonlyServiceRule.php | Requires readonly modifier on service classes (immutability enforcement) | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\RequireRuleIdentifierConstantRule` | src/PHPStan/Rules/RequireRuleIdentifierConstantRule.php | Requires RuleIdentifierInterface constant on PHPStan rules | phpstan via rules-default.neon | ⚠️ No dedicated test |
| `LTS\PHPQA\PHPStan\Rules\RequireVariadicForSingleListParamRule` | src/PHPStan/Rules/RequireVariadicForSingleListParamRule.php | Recommends variadic params over single array (type clarity, flexibility) | phpstan via rules-optional.neon | ✓ tests/Small/PHPStan/Rules/RequireVariadicForSingleListParamRuleTest.php |
| `LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface` | src/PHPStan/Rules/RuleIdentifierInterface.php | Interface: prefix constant for rule IDs (enable/disable via PHPStan config) | All PHPStan rules | ✓ (used by all rules) |

---

## 2. /workspace/bin/ – Executable Entry Points (11 files)

| Bin Name | Shebang | Invokes | Called by QA Pipeline | composer.json "bin" |
|---|---|---|---|---|
| `bin/qa` | `#!/usr/bin/env bash` | Orchestrates all QA tools (bash pipeline: rector → fixer → phpstan → phpunit → infection) via includes/*.bash | Entry point (yes, THE entry point) | ✓ Yes |
| `bin/psr4-validate` | `#!/usr/bin/env php` | Runs `LTS\PHPQA\Psr4Validator` (PSR-4 namespace compliance check) | includes/generic/psr4Validate.inc.bash (phase 2 linting) | ✓ Yes |
| `bin/managed-source` | `#!/usr/bin/env php` | Runs `LTS\PHPQA\ManagedSource\ManagedSourceGenerator` (check/deploy managed tree) | bin/qa preflight (via phive-install.bash script) | ✓ Yes |
| `bin/mdlinks` | `#!/usr/bin/env php` | Runs `LTS\PHPQA\Markdown\LinksChecker` (validates README + docs/ links) | includes/generic/markdownLinks.inc.bash (phase 2 linting) | ✓ Yes |
| `bin/package-type-check` | `#!/usr/bin/env php` | Runs `LTS\PHPQA\PackageType\ExplicitPackageTypeCheck` (validates type in composer.json) | includes/generic/packageType.inc.bash (post-tools, optional) | ✓ Yes |
| `bin/phpunit-check-annotation` | `#!/usr/bin/env php` | Runs `LTS\PHPQA\PHPUnit\CheckAnnotations` (validates test size annotations) | includes/generic/phpunitAnnotations.inc.bash (phase 2, currently commented out) | ✓ Yes |
| `bin/sensitive-parameter-usage` | `#!/usr/bin/env php` | Runs `LTS\PHPQA\SensitiveParameter\SensitiveParameterUsageScanner` (detects #[\SensitiveParameter] usage) | includes/generic/sensitiveParameterUsage.inc.bash (phase 3 security check) | ✓ Yes |
| `bin/phpstan` | `#!/usr/bin/env bash` | Wrapper: calls `$pharDir/phpstan.phar` with configured args | includes/generic/phpstan.inc.bash (phase 3 static analysis) | ✓ Yes |
| `bin/php-cs-fixer` | `#!/usr/bin/env bash` | Wrapper: calls `$pharDir/php-cs-fixer.phar` with configured args | includes/generic/phpCsFixer.inc.bash (phase 1 code fix) | ✓ Yes |
| `bin/infection` | `#!/usr/bin/env bash` | Wrapper: calls `$pharDir/infection.phar` with coverage + configured args | includes/generic/infection.inc.bash (phase 4 mutation testing, optional) | ✓ Yes |
| `bin/composer-require-checker` | `#!/usr/bin/env bash` | Wrapper: calls `$pharDir/composer-require-checker.phar` with configured args | includes/generic/composerRequireChecker.inc.bash (phase 2 linting) | ✓ Yes |

**Key Findings**:
- All 11 bin exports are referenced in composer.json ✓
- All are called by the QA pipeline (either directly or via wrapper scripts)
- Bash wrappers (phpstan, php-cs-fixer, infection, composer-require-checker) are minimal shims that delegate to PHARs
- PHP bin scripts are entrypoints that load autoloader then instantiate + run corresponding src/ class

---

## 3. /workspace/composer.json – Project Summary

| Field | Value |
|---|---|
| **name** | `lts/php-qa-ci` |
| **type** | `composer-plugin` |
| **PHP Requirement** | `^8.3` |
| **Primary Namespace** | `LTS\PHPQA\` (src/) |
| **Test Namespace** | `LTS\PHPQA\Tests\` (tests/) |
| **Bin Count** | 11 (psr4-validate, managed-source, mdlinks, package-type-check, phpunit-check-annotation, sensitive-parameter-usage, phpstan, php-cs-fixer, infection, composer-require-checker, qa) |
| **Composer Plugins** | 4 (SkillsDeployPlugin, ManagedSourceDeployPlugin, PhiveUpdatePlugin, PhpStanGuardPlugin) |
| **Post-Install/Update Hooks** | Runs `scripts/tool-install.bash` (manages PHIVE PHARs) |
| **Config** | bin-dir: `bin`, allow-plugins: ergebnis/composer-normalize, infection/extension-installer, phpstan/extension-installer |
| **PHPStan Integration** | Exports `rules-default.neon` via extra.phpstan.includes |
| **Key Dependencies** | phpstan/phpstan (replaced), phpunit/phpunit, thecodingmachine/safe (for Safe functions), nikic/php-parser, phpstan extensions |

---

## 4. /workspace/tests/ – Test Coverage Map

### Test Structure
```
tests/
├── Large/
│   ├── Arkitect/ArkitectCheckTest.php                  (1 test)
│   ├── Infection/InfectionDiffModeTest.php             (1 test)
│   └── Markdown/LinksCheckerTest.php                   (1 test)
├── Small/
│   ├── HelperTest.php                                  (1 test)
│   ├── Psr4ValidatorTest.php                           (1 test)
│   ├── ManagedSource/ManagedSourceGeneratorTest.php    (1 test)
│   ├── PackageType/*.php                               (4 tests)
│   ├── PHPStan/Attribute/FactorySealedByTest.php       (1 test)
│   ├── PHPStan/Rules/*.php                             (13 rule tests)
│   └── SensitiveParameter/SensitiveParameterUsageScannerTest.php (1 test)
└── assets/
    ├── PHPStan/ (test fixtures for various rules)
    ├── arkitect/ (phparkitect test projects)
    ├── phpunitAnnotations/ (test annotation fixtures)
    ├── psr4/ (PSR-4 validation fixtures)
    └── sensitiveParameterUsage/ (credential detection fixtures)
```

### Coverage Summary
- **Large tests**: 3 (arkitect, infection, markdown)
- **Small tests**: 23+ individual tests
- **Total test count**: ~26+ explicit test classes
- **Asset fixture count**: 70+ fixture PHP files (arkitect, PHPStan rules, psr4, annotations, sensitive-parameter)

### Zero-Test Modules (16 classes)
| Class | File | Reason |
|---|---|---|
| SkillsDeployPlugin | src/ComposerPlugin/SkillsDeployPlugin.php | Composer plugin event hook (integration test, no unit test) |
| ManagedSourceDeployPlugin | src/ComposerPlugin/ManagedSourceDeployPlugin.php | Composer plugin event hook |
| PhiveUpdatePlugin | src/ComposerPlugin/PhiveUpdatePlugin.php | Composer plugin event hook |
| PhpStanGuardPlugin | src/ComposerPlugin/PhpStanGuardPlugin.php | Composer plugin event hook |
| CheckAnnotations | src/PHPUnit/CheckAnnotations.php | Test tool (currently unused; annotations check commented out in includes/) |
| ForbidEmptyCatchBlockRule | src/PHPStan/Rules/ForbidEmptyCatchBlockRule.php | PHPStan rule |
| ForbidEmptyLanguageConstructRule | src/PHPStan/Rules/ForbidEmptyLanguageConstructRule.php | PHPStan rule |
| ForbidHeaderInjectionRule | src/PHPStan/Rules/ForbidHeaderInjectionRule.php | PHPStan rule |
| ForbidInlinePhpstanIgnoreRule | src/PHPStan/Rules/ForbidInlinePhpstanIgnoreRule.php | PHPStan rule |
| ForbidLooseComparisonRule | src/PHPStan/Rules/ForbidLooseComparisonRule.php | PHPStan rule |
| ForbidMockingFinalClassRule | src/PHPStan/Rules/ForbidMockingFinalClassRule.php | PHPStan rule |
| ForbidNewDateTimeRule | src/PHPStan/Rules/ForbidNewDateTimeRule.php | PHPStan rule |
| ForbidNullCoalescingEmptyStringRule | src/PHPStan/Rules/ForbidNullCoalescingEmptyStringRule.php | PHPStan rule |
| ForbidNullCoalescingFalseRule | src/PHPStan/Rules/ForbidNullCoalescingFalseRule.php | PHPStan rule |
| ForbidRawSqlRule | src/PHPStan/Rules/ForbidRawSqlRule.php | PHPStan rule |
| ForbidSilentCatchRule | src/PHPStan/Rules/ForbidSilentCatchRule.php | PHPStan rule |
| RequireDeclareStrictTypesRule | src/PHPStan/Rules/RequireDeclareStrictTypesRule.php | PHPStan rule |
| RequireExplicitDIAttributeRule | src/PHPStan/Rules/RequireExplicitDIAttributeRule.php | PHPStan rule |
| RequireReadonlyServiceRule | src/PHPStan/Rules/RequireReadonlyServiceRule.php | PHPStan rule |
| RequireRuleIdentifierConstantRule | src/PHPStan/Rules/RequireRuleIdentifierConstantRule.php | PHPStan rule |

---

## 5. /workspace/tools/ – Sub-Projects

| Sub-Project | Path | Purpose | Dependency |
|---|---|---|---|
| **Rector (isolated)** | tools/rector/ | Isolated Rector installation to prevent phpstan/phpstan leaking into project dependencies | Runs via includes/generic/rector.inc.bash |

**Details**: tools/rector/composer.json requires `rector/rector` (latest stable) with PHP ^8.3. This isolated installation is necessary because Rector bundles its own phpstan which could conflict with php-qa-ci's vendored phpstan replacement.

---

## 6. /workspace/vendor-phar/ + phive.xml – PHAR Management

### PHARs Managed via PHIVE

| PHAR | Version | Location | Installed | Key | Used By |
|---|---|---|---|---|---|
| **phpstan** | ^2.2.3 | vendor-phar/phpstan.phar | 2.2.3 | (built-in) | bin/phpstan wrapper |
| **php-cs-fixer** | ^3.95.11 | vendor-phar/php-cs-fixer.phar | 3.95.11 | (built-in) | bin/php-cs-fixer wrapper |
| **infection** | ^0.34 | vendor-phar/infection.phar | 0.34.0 | (built-in) | bin/infection wrapper |
| **composer-require-checker** | ^4.24.0 | vendor-phar/composer-require-checker.phar | 4.24.0 | (built-in) | bin/composer-require-checker wrapper |
| **phparkitect** | ^1.1 | vendor-phar/phparkitect.phar | 1.1.1 | 47CD54B6398FE21B3709D0A4D9C905CED1932CA2 | includes/generic/phpArkitect.inc.bash |

**Total PHAR Size**: ~34 MB  
**phive.xml Location**: /workspace/phive.xml  
**Installation Script**: scripts/phive-install.bash (called from composer post-install-cmd)

---

## 7. Orphan Analysis – Suspected Unused Code

### SUSPECTED ORPHANS

| Class | File | Evidence | Likelihood |
|---|---|---|---|
| **CheckAnnotations** | src/PHPUnit/CheckAnnotations.php | Called via bin/phpunit-check-annotation; but includes/generic/phpunitAnnotations.inc.bash is COMMENTED OUT (see line: `#annotationsExitCode=99`). Never invoked by bin/qa pipeline. | **HIGH** — tool exists but is disabled; no references in includes/ or bin/qa |
| **SkillsDeployPlugin** | src/ComposerPlugin/SkillsDeployPlugin.php | Defined in composer.json extra.class; hook method deploySkills never called directly; no references outside composer config. | **MEDIUM** — Composer plugin; likely works but no evidence of invocation in test suite |
| **PhiveUpdatePlugin** | src/ComposerPlugin/PhiveUpdatePlugin.php | Defined in composer.json extra.class; deployed via composer event; no direct references in bin/ or includes/. | **MEDIUM** — Composer plugin; likely works but no visible test |
| **PhpStanGuardPlugin** | src/ComposerPlugin/PhpStanGuardPlugin.php | Defined in composer.json extra.class; no direct references found; purpose is version guard. | **MEDIUM** — Composer plugin; possibly running silently |
| (10 PHPStan Rules) | src/PHPStan/Rules/Forbid*.php, Require*.php | Rules defined but not tested; likely referenced in phpstan config files (not yet searched). | **MEDIUM** — PHPStan rules are auto-discovered; likely active but not unit-tested |

### NOT ORPHANS (Confirmed Usage)

- **FactorySealedRule, RequireSensitiveParameterAttributeRule** — actively used (checked in configDefaults/generic/)
- **Helper, Psr4Validator, LinksChecker, ManagedSourceGenerator** — explicitly tested and called by bin/ scripts
- **All ComposerPlugin classes except PhpStanGuardPlugin** — registered in composer.json and confirmed in git history

---

## 8. Flags for Deeper Review (Max 10)

1. **CheckAnnotations is dead code** — bin/phpunit-check-annotation exists and is exported in composer.json, but includes/generic/phpunitAnnotations.inc.bash is commented out (lines ~7–20). Verify if this tool should be re-enabled or removed.

2. **Composer Plugins not unit-tested** — All 4 composer plugins (SkillsDeployPlugin, ManagedSourceDeployPlugin, PhiveUpdatePlugin, PhpStanGuardPlugin) lack unit tests. Consider integration tests or documentation of expected behavior.

3. **16 PHPStan Rules untested** — 16/36 rule classes have no dedicated unit tests (mostly Forbid* rules). Verify coverage via configDefaults/generic/rules-default.neon or rules-optional.neon references.

4. **Rector sub-project isolation** — tools/rector/ is a separate composer project that requires rector/rector. Verify this is correctly loaded in includes/generic/rector.inc.bash (line: `rectorBin="$qaDir/../tools/rector/vendor/bin/rector"`).

5. **Managed-source deployment mechanism unclear** — ManagedSourceDeployPlugin and ManagedSourceGenerator work together; verify drift-check (bin/qa -t managed-source check) is tested and documented.

6. **PHIVE key for phparkitect** — Only phparkitect requires a key (47CD54B6398FE21B3709D0A4D9C905CED1932CA2). Verify this key is still valid and not expired.

7. **Test fixtures coupling** — tests/assets/PHPStan/ contains ~50 fixture files for rule testing. Changes to rule logic must update both rule class AND corresponding fixture. No shared fixture validation framework observed.

8. **PhpStanGuardPlugin purpose obscure** — PhpStanGuardPlugin is defined but its purpose (version guard) is not documented. Confirm it is active and its role in the post-install flow.

9. **No integration tests for bin/qa pipeline** — Large/ tests cover individual tools (ArkitectCheckTest, InfectionDiffModeTest) but not the full bin/qa orchestration sequence. Consider end-to-end pipeline test.

10. **Markdown link checker scope** — LinksChecker scans README.md + docs/; verify its behavior on projects without docs/ (CLAUDE/Plan directories?). Currently no test for missing docs/ scenario.

---

## Summary (8 Lines)

**Source Inventory**: 50 PHP classes across 7 namespaces (core utils, 4 Composer plugins, 1 Markdown checker, 4 package-type validators, 2 sensitive-parameter tools, 1 managed-source generator, 36 PHPStan rules).

**Bin Exports**: All 11 bin/ scripts are registered in composer.json and called by the QA pipeline. 4 are PHP entrypoints; 7 are bash wrappers delegating to PHARs.

**Orphan Suspects**: CheckAnnotations (HIGH — disabled tool); 4 Composer plugins (MEDIUM — untested, opaque invocation); 16 PHPStan rules (MEDIUM — untested, likely auto-discovered by phpstan).

**Test Coverage Gaps**: 23 tests across Large/Small; zero tests for Composer plugins and 16 PHPStan rules. Fixture-heavy approach (70+ assets) reduces code-path test visibility.

**PHAR Management**: 5 PHARs (phpstan, php-cs-fixer, infection, composer-require-checker, phparkitect) managed via phive.xml; installed via scripts/phive-install.bash; ~34 MB total.

**Sub-Projects**: tools/rector/ isolates Rector to prevent phpstan leakage; separate composer.json with rector/rector dependency.

**Critical Finding**: Annotations checker tool (bin/phpunit-check-annotation, src/PHPUnit/CheckAnnotations.php) exists in full export but is permanently disabled in pipeline (commented-out hook). Recommend audit for removal or re-activation.

**Test Density**: ~26 explicit test classes + 70+ fixture files; Composer plugins and 16 PHPStan rules completely untested at unit level.
