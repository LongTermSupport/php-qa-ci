# Plan 00010: Re-audit of the register entry's No and Partial rows

The draft for [Task 6.2](PLAN.md). Every row the
[register entry](../../../../remote-docs/defence-before-fix.github.io/tools/php-qa-ci.md) grades
`No` or `Partial`, re-graded by running the command or test named, first on branch
`feature/dbf-justification-include-chain` and again against release 85.3.0 (commit `cb5b4eb`).
Published with the Owner's authorisation as
[register pull request #12](https://github.com/Defence-Before-Fix/defence-before-fix.github.io/pull/12)
against `next`, whose merge is the register editor's.

| Spec      | Clause | Register | Re-audit | Evidence                                                                                                                                                                                                           |
| --------- | ------ | -------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Detector  | 6.2    | Partial  | Partial  | Every `phpqaci.*` identifier resolves offline (69 of 69 in `bin/rule-doc --list` at 85.3.0). `bin/rule-doc method.notFound` names the phpstan.org page and says the catalogue is not carried offline: Decision 6.  |
| Detector  | 6.3    | Partial  | Partial  | Bundle and lane pages ship with the package. PHPStan's own rules ship none (Decision 6); PHPArkitect's tier rules are not individually paged (Decision 5).                                                         |
| Detector  | 6.4    | Partial  | Yes      | `RuleDocumentationTest` fails the build on a declared identifier with no page, and on a page with no construction section or one restating the summary.                                                            |
| Detector  | 7.2    | No       | Yes      | `rules-default.neon` sets `reportIgnoresWithoutComments: true`, behind `phpqaci.inlinePhpstanIgnore`; `RulesDefaultInlineIgnoreReasonTest`.                                                                        |
| Toolchain | 4.1    | No       | No       | Every lane prints a stable identifier (`EveryLaneNamesItselfTest`). PHPArkitect's per-rule identity (Decision 5) and PHPStan's native catalogue offline (Decision 6) remain, declared in `known-gaps` with probes. |
| Toolchain | 4.2    | Partial  | Yes      | `bin/rules .` prints no `no documentation page` row; `bin/rule-doc phpqaci.packageType` resolves to its page.                                                                                                      |
| Toolchain | 4.3    | Partial  | Yes      | `phparkitect-baseline.json` is never read (`--skip-baseline`, named when present: `PhpArkitectToolTest`). An included PHPStan baseline is walked by the justification lane and fails it without comments.          |
| Toolchain | 5.1    | Partial  | Yes      | `bin/rules . --json`: every lane except the phase runners and `uniterate` carries an identifier and a `docPath`.                                                                                                   |
| Toolchain | 6.2    | Partial  | Yes      | `bin/qa -t pij` reads `qaConfig/phpstan.neon` and every file it includes (`NeonIncludeChain`), and fails on an include it cannot follow.                                                                           |
| Toolchain | 6.4    | Partial  | Yes      | [docs/defence-before-fix-defaults.md](../../../../docs/defence-before-fix-defaults.md) states the Owner, record, sweep, fixture and calibration defaults, linked from the consumer `CLAUDE.md` block.                 |
| Toolchain | 7.1    | No       | Yes      | `bin/rules --write-agent-summary` writes one line per active defence into the `CLAUDE.md` region; held current here by `AgentContextIsCurrentTest`.                                                                |
| Toolchain | 8.1    | Partial  | Yes      | `RuleDocumentationTest` requires a page per identifier that states a correct construction, with fixtures proving the guard fires.                                                                                  |

The declaration itself is now held to the repository by `DefenceBeforeFixDeclarationTest`
(Task 1.2), so the three remaining `known-gaps` entries are each an Owner decision with a probe
that fails when the gap closes.
