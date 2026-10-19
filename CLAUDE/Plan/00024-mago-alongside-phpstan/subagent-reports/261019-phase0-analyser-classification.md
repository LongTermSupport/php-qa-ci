# Plan 00024 Task 0.5: the 211 analyser disagreements, classified

Scope: Mago 1.57.0 `analyze` with `untracked/scratch/mago-research/cfg/mago-aligned.toml`, over the
paths the research used (`src`, `tests`; `tests/assets`, `vendor` and the extracted PHPStan sources
as includes; `tests/Small/ComposerPlugin/**` excluded). Fact-finding only: no tracked file other
than this report was changed, `bin/qa` was not run, and PHPStan ran only on scratch fixtures.

Scratch artefacts are in `untracked/scratch/mago-phase0-analyse/`:

- `analyze.json`: the raw Mago run.
- `flat.tsv`: one line per finding.
- `classify.php`: the classifier that assigns each finding a family and a class.
- `classified.tsv` and `summary.tsv`: its output.
- `fx/*.php`: the both-tool fixtures.
- `run.bash`: runs Mago and PHPStan on one fixture.
- `mago-fx.toml` and `phpstan-fx.neon`: the fixture configs. The neon is level max plus
  `rules-default.neon` and `rules-optional.neon`; strict-rules and phpstan-phpunit load through
  the extension installer, which was confirmed by strict-rules identifiers such as
  `identical.alwaysTrue` appearing in the fixture runs.
- `patches/` and `analyze-patched.json`: the Mago `patches` experiment.

## Facts

### Reproduction

- **Exact match:** 211 findings, of which 148 are Error, 30 Warning and 33 Help. This is the same
  count and the same per-code distribution as `out/analyze-aligned.json`. Mago exits 1 and warns
  about the one parse-error fixture in `tests/assets/psr4/projectInValid`, as in the research.
- **Line numbers:** Mago's JSON `span.start.line` is **0-based**. Every `file:line` below is
  1-based, converted from it. Some of the research report's citations are the raw 0-based value:
  `src/Helper.php:44` is line 45, and `RunLockSignalTest.php:156` is line 157.

### Classes used

- **A**: Mago's claim is true of the code as written, and a rewrite exists that both tools accept.
- **B**: a Mago false positive, where Mago is wrong about the types or the behaviour.
  - **Bw** is the subset of B where a both-accept workaround was nonetheless verified on a
    fixture.
  - Plain **B** means no code workaround was verified, either because none exists or because the
    only one is an inline `@var`, which PHPStan's own output tells agents not to use.
- **C**: a contradiction of the C1 class.
- **D**: unclear.

"Verified" means the as-is form and the rewrite were both run on a scratch fixture. The as-is form
was flagged by Mago and accepted by PHPStan; the rewrite was accepted by both. The PHPStan run was
level max with strict-rules and the phpqaci rules. Where a fixture also raised phpqaci findings
about the fixture itself, such as `variadicOverArrayParameter` on a helper, those are noted and
ignored.

### Root-cause families

The 211 findings come from 24 root causes. F1, F2 and F14 account for 153 of them (73%):

| Family | Root cause                                                                                                             | Findings | Class                              |
| ------ | ---------------------------------------------------------------------------------------------------------------------- | -------- | ---------------------------------- |
| F1     | By-ref out-variable not initialised before `\Safe\preg_match`, `preg_match_all`, `stream_socket_server` or `proc_open` | 91       | 44 A, 47 Bw                        |
| F2     | `PHPStan\Node\CollectedDataNode::get()` template not resolved, cascading through two rules                             | 50       | B                                  |
| F14    | A constant array's key or spread type is not resolved                                                                  | 12       | B                                  |
| F3     | A property's narrowing is dropped across an intervening call (C6)                                                      | 8        | A                                  |
| others | 20 smaller causes                                                                                                      | 50       | mixed: 20 A, 16 Bw, 11 B, 2 C, 1 D |

**F1, by-ref out-variables (91 findings).** All 30 `reference-to-undefined-variable`, all 14
`possibly-undefined-variable`, and 44 of the 73 `mixed-argument` findings are on the out-parameter
itself. Three more findings follow from it: two `mixed-return-statement` (`return $match[1]`, seen
as `nonnull`) and one `mixed-argument` (`basename($matches[1])`).

- **The cause:** Safe's stubs declare `?array &$matches = null` with no `@param-out`, so Mago checks
  the undefined input against the parameter type.

- **Native functions behave differently:** native `preg_match` draws only the Help-level
  `reference-to-undefined-variable`, not `mixed-argument` (`fx/ByRef.php`).

- **Verified rewrite:** initialise the variable, inside the loop where the call is in a loop:

  ```php
  $m = null;
  if (1 === \Safe\preg_match(self::P, $s, $m) && isset($m[1])) { return $m[1]; }
  ```

  Both tools accept it, and `$m = []` works too. The same holds for `stream_socket_server`
  (`$errno = null; $errstr = null;`). The repository already uses this form in
  `src/Markdown/LinksChecker.php:319`.

- **Exception:** `preg_match_all` followed by `return $m[1] ?? []` is still `mixed` in **both**
  tools. That is not a disagreement, and the repository code does not do it.

- **Patching Safe's stubs does not help:** a Mago `patches` file giving the Safe stubs a
  `@param-out` did not change these counts.

**F2, the collector cascade (50 findings, all in two files).**

- **Where:** `src/PHPStan/Rules/ForbidUnvalidatedRegexPatternRule.php` and
  `src/PHPStan/Rules/RequireProcessStopOnThrowRule.php`.
- **Root:** PHPStan's `CollectedDataNode::get()` declares
  `@template TCollector of Collector<Node, TValue>` *before* `@template TValue`. Mago reports the
  bound as `unknown-ref(PHPStan\Node\TValue)`, rejects the call with 7 `invalid-argument`, and then
  types everything collected as `mixed`.
- **Cascade:**
  - 27 `mixed-argument`
  - 7 `invalid-argument`
  - 6 `invalid-destructuring-source`
  - 7 `less-specific-nested-argument-type`
  - 2 `less-specific-argument`, on `$testCases` keys derived from the collected data
  - 1 `mixed-operand`
- **Patch experiment:** a Mago patch redeclaring `get()` with the templates in the other order
  removed the 7 `invalid-argument` findings (211 became 206). Mago still did not infer `TValue`
  from `class-string<ConcreteCollector>`, so the cascade remained, and the patch added one
  `redundant-docblock-type`.
- **No code fix found.** An inline `@var` per `foreach` would hide the cascade but not the
  call-site error.

**F3, narrowing dropped across a call (8 findings, class A).** Mago forgets a property's narrowing
when a method call comes between the check and the use. PHPStan keeps it. The verified fix is to
copy the property into a local variable first (`fx/Rewrites.php` `narrowRewrite`):

```php
$class = $node->class; if (!$class instanceof Name) { return []; } ... $scope->resolveName($class);
```

The research verified the same fix for `RunLockSignalTest` (C6, probe P4).

**C1 has a both-accept form.** The guard in `src/Helper.php:45` is
`!isset($_SERVER['PWD']) || !\is_string($_SERVER['PWD'])`. It draws `redundant-type-comparison`
and `redundant-logical-operation`, both at Help level. Without the `is_string`, PHPStan reports
`return.type` ("should return string but returns mixed"). `fx/C1.php` shows a form both tools
accept:

```php
$pwd = $_SERVER['PWD'] ?? null;
if (!\is_string($pwd)) { exit('no PWD in _SERVER'); }
```

The contradiction is therefore tied to one construction, the direct guard on the superglobal
element, rather than to the requirement itself. A rewrite removes it from R, but the natural guard
stays a trap, and the AGREEMENT I4 probe for C1 would still fail.

**A second C1-class trap appeared in a rewrite, not in R.** The 5 `src/Psr4Validator.php:182/194`
findings come from `new class(...) extends SplHeap` with no template. They are
`invalid-argument`, `missing-template-parameter`, `less-specific-nested-return-statement` and two
`incompatible-parameter-name`. The obvious fix fails:

- **What fails:** a named `/** @extends SplHeap<SplFileInfo> */` class whose `compare()` keeps the
  `instanceof SplFileInfo` guard.
- **Why:** PHPStan types `SplHeap::compare()`'s parameters as `mixed`, so it requires the guard;
  typing them `SplFileInfo` gives `method.childParameterType`. Mago binds them to `SplFileInfo`
  and calls the guard `redundant-type-comparison` plus `impossible-condition`.
- **The form both accept** (`fx/Rewrites3.php` `sortedFiles`) drops `SplHeap`: collect into a
  `list<SplFileInfo>` and `usort` it.

**Mago's analyser-code exit status depends on level.** Help-level codes
(`reference-to-undefined-variable`, `redundant-*`) and Warning-level codes do not fail a run at the
default `minimum-fail-level = error`. `fx/C1.php` exits 0 in Mago despite two findings.

### Per code

Columns A, B, Bw, C and D are finding counts. Examples are 1-based `file:line` with the offending
code.

| Code                                  | Count | Level   | A   | B   | Bw  | C   | D   | Examples                                                                                                                                                                                                                                                                                                                                                                                                                                |
| ------------------------------------- | ----- | ------- | --- | --- | --- | --- | --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| mixed-argument                        | 73    | Error   | 0   | 27  | 46  | 0   | 0   | F1 Bw: `src/Pipeline/Lane/Opcache/DumpParser.php:40` `\Safe\preg_match(self::LOCATION, $line, $location)`; `tests/Large/Markdown/LinksCheckerTest.php:187` `stream_socket_server(..., $errno, $errstr)`. F2 B: `RequireProcessStopOnThrowRule.php:76` `array_merge(...$collected)`. F5 Bw: `NeonIncludeChain.php:80` (`/** @var list<string> $includes */` before `foreach` not honoured; fix: build the list with an `is_string` loop) |
| reference-to-undefined-variable       | 30    | Help    | 30  | 0   | 0   | 0   | 0   | `src/HooksDaemon/HooksDaemonVersionReader.php:30` `preg_match(..., $found)`; `src/IssueMonitor/IssueMonitor.php:240` `$cut`; `tests/Small/Pipeline/Runner/PipelineTest.php:112` `$ran`. Fix: initialise (F1)                                                                                                                                                                                                                            |
| less-specific-argument                | 15    | Error   | 0   | 15  | 0   | 0   | 0   | F14: `tests/Small/Pipeline/Lane/InfectionToolTest.php:1033` `builder(env: self::AUTO)`, where `AUTO = [...self::FLOORS, 'infectionDiffBase' => 'auto']` is seen as `array<array-key, mixed>`; `src/Pipeline/Process/PhpInvoker.php:87` `self::XDEBUG_OFF = [self::XDEBUG_MODE => 'off']`. F15: `RegexPatternVariableWriteCollector.php:241` `$index` from `getArgs()` seen as `array-key`. F2: 2                                        |
| less-specific-nested-argument-type    | 14    | Error   | 0   | 10  | 4   | 0   | 0   | F12 Bw: `src/HooksDaemon/HooksDaemonCliLocator.php:95` `implode('/', $names)` after `array_pop($names)` in a loop. F11 Bw: `IssueMonitor.php:237` `array_filter(..., \is_string(...))`. F11 B: `tests/Small/Pipeline/Agent/FileReportWriterTest.php:194` `array_column($files, 'path')`. F13 B: `LogArchiver.php:54`. F2 B: 7                                                                                                           |
| possibly-undefined-variable           | 14    | Warning | 14  | 0   | 0   | 0   | 0   | `src/Changelog/ReleasedSections.php:38`; `src/PHPStan/ProjectRecord/IgnoreErrorsJustificationDetector.php:55` (`$m` in a loop). Fix: initialise inside the loop (verified)                                                                                                                                                                                                                                                              |
| invalid-argument                      | 8     | Error   | 1   | 7   | 0   | 0   | 0   | F2: `ForbidUnvalidatedRegexPatternRule.php:56` `$node->get(ErrorHandlerRestoringFinallyCollector::class)`. F10 A: `src/Psr4Validator.php:182` `new class($iterator) extends SplHeap`                                                                                                                                                                                                                                                    |
| possibly-invalid-argument             | 8     | Error   | 6   | 1   | 1   | 0   | 0   | F6 A: `src/HooksDaemon/FullQaBlockerYamlEditor.php:71` `str_repeat(' ', $eventIndent)` (type it `int<0,max>`, research C7). F3 A: `ForbidNewDateTimeRule.php:54` `$scope->resolveName($node->class)`. F7 Bw: `src/Changelog/ChangelogParser.php:75`. F9 B: `tests/Small/PHPStan/RuleDocResolverTest.php:365` (class constant built by concatenation, seen as `string` rather than non-empty)                                            |
| invalid-destructuring-source          | 6     | Error   | 0   | 6   | 0   | 0   | 0   | F2: `RequireProcessStopOnThrowRule.php:82` `foreach ($collected as [$class, $method, ...])`; `ForbidUnvalidatedRegexPatternRule.php:71`                                                                                                                                                                                                                                                                                                 |
| invalid-type-cast                     | 6     | Warning | 6   | 0   | 0   | 0   | 0   | `src/PHPStan/ActiveRulesLister.php:328` `(array)($decoded['rules'] ?? [])`. Fix (verified): `$rules = $decoded['rules'] ?? []; foreach (\is_array($rules) ? $rules : [] as ...)`                                                                                                                                                                                                                                                        |
| less-specific-nested-return-statement | 5     | Error   | 1   | 0   | 4   | 0   | 0   | F11 Bw: `IgnoredPathsInfectionConfig.php:263` and `InfectionTool.php:309` `array_values(array_filter($value, \is_string(...)))`; `TempLeakLedger.php:69` (closure callback). F10 A: `Psr4Validator.php:182`                                                                                                                                                                                                                             |
| mixed-return-statement                | 3     | Error   | 0   | 1   | 2   | 0   | 0   | F1 Bw: `src/PhpstanDocs/PhpstanDocsCatalogue.php:73` `return $match[1];`. F22 B: `tests/Small/PHPStan/Rules/ScopeStubTrait.php:31`                                                                                                                                                                                                                                                                                                      |
| possible-method-access-on-null        | 3     | Error   | 3   | 0   | 0   | 0   | 0   | F3: `tests/Large/Pipeline/RunLockSignalTest.php:161,163` `$this->qa->isRunning()` after narrowing                                                                                                                                                                                                                                                                                                                                       |
| incompatible-parameter-name           | 2     | Warning | 2   | 0   | 0   | 0   | 0   | F10: `Psr4Validator.php:194` `compare($item1, $item2)` against `SplHeap::compare($value1, $value2)`                                                                                                                                                                                                                                                                                                                                     |
| invalid-return-statement              | 2     | Error   | 2   | 0   | 0   | 0   | 0   | F3: `RunLockSignalTest.php:157` `return [$this->qa, $child];`; `src/PHPStan/Rules/RegexPatternOwnership.php:148` (`$expr->name` narrowed, then `isParameter()` called)                                                                                                                                                                                                                                                                  |
| mixed-operand                         | 2     | Error   | 1   | 1   | 0   | 0   | 0   | F17 A: `src/Markdown/LinksChecker.php:312` `$lastStatus ?? $lastError ?? '...'` (`$lastStatus` first set inside `try`; fix: `$lastStatus = null;` before the loop). F2 B: `RequireProcessStopOnThrowRule.php:99`                                                                                                                                                                                                                        |
| non-existent-method                   | 2     | Error   | 1   | 1   | 0   | 0   | 0   | F3 A: `UnownedRegexPatternCollector.php:96` `$node->name->toString()`. F22 B: `ScopeStubTrait.php:31` `self::createStubForIntersectionOfInterfaces(...)`                                                                                                                                                                                                                                                                                |
| possibly-false-operand                | 2     | Warning | 1   | 0   | 1   | 0   | 0   | F17 A: `tests/Large/GitHooks/PreCommitCheckVendorUncommittedTest.php:143` `. getenv('PATH')` (fix: `$path = getenv('PATH'); ... \is_string($path) ? ...`). F17b Bw: `LinksChecker.php:155`                                                                                                                                                                                                                                              |
| possibly-null-argument                | 2     | Error   | 0   | 0   | 2   | 0   | 0   | F18: `tests/Small/DefenceBeforeFixDeclarationTest.php:186,190` `str_starts_with($status, ...)` after `\in_array(null, [$document, $major, $minor, $status], true)`. Fix: `null === $a \|\| null === $b ...`                                                                                                                                                                                                                             |
| possibly-null-operand                 | 2     | Warning | 2   | 0   | 0   | 0   | 0   | `src/PHPStan/Rules/RequireExplicitDIAttributeRule.php:116` `$scope->getNamespace() . '\\'` (fix: `null === $ns ? $name : $ns . '\\' . $name`); `tests/Small/PHPStan/ActiveRulesListerTest.php:327` (fix: `\sprintf('%s %s', ...)`)                                                                                                                                                                                                      |
| redundant-logical-operation           | 2     | Help    | 0   | 1   | 0   | 1   | 0   | C: `src/Helper.php:45` (C1). F21 B: `src/PHPStan/Rules/RepeatedStringLiteralCollector.php:59` `!isset($this->arrayKeys[$node])`                                                                                                                                                                                                                                                                                                         |
| avoid-catching-error                  | 1     | Warning | 0   | 1   | 0   | 0   | 0   | `src/Pipeline/Lane/Infection/CommentOnlyChange.php:60` `catch (ParseError $parseError)`. `PhpToken::tokenize(..., TOKEN_PARSE)` reports invalid PHP only by throwing `ParseError`, so catching it is the API's contract                                                                                                                                                                                                                 |
| docblock-type-mismatch                | 1     | Error   | 0   | 0   | 1   | 0   | 0   | F19: `LinksChecker.php:75` `/** @var array<int, string> $file */` on a `RegexIterator::GET_MATCH` value. Fix (verified): iterate `RecursiveIteratorIterator` and `\Safe\preg_match` the path                                                                                                                                                                                                                                            |
| invalid-property-assignment-value     | 1     | Error   | 0   | 1   | 0   | 0   | 0   | F21: `RepeatedStringLiteralCollector.php:34` `new SplObjectStorage()` inferred as `<never, never>`. The only verified workaround is an inline `@var`                                                                                                                                                                                                                                                                                    |
| list-destructure-string-key           | 1     | Warning | 0   | 0   | 0   | 0   | 1   | `LinksChecker.php:169` `[, $anchor, $href] = $link;` with `@param array<int\|string, string> $link`                                                                                                                                                                                                                                                                                                                                     |
| missing-template-parameter            | 1     | Error   | 1   | 0   | 0   | 0   | 0   | F10: `Psr4Validator.php:182`                                                                                                                                                                                                                                                                                                                                                                                                            |
| paradoxical-condition                 | 1     | Error   | 0   | 1   | 0   | 0   | 0   | F8: `RequireProcessStopOnThrowRule.php:263` `$tearDownRuns && \in_array($receiver, $tearDownStops, true)`                                                                                                                                                                                                                                                                                                                               |
| possibly-false-argument               | 1     | Error   | 1   | 0   | 0   | 0   | 0   | `tests/Support/TempLeak/TempLeakDirectory.php:51` `new SplFileInfo($directory)->getOwner()`. Fix (verified): `\Safe\fileowner($directory)`                                                                                                                                                                                                                                                                                              |
| possibly-false-array-access           | 1     | Warning | 0   | 0   | 1   | 0   | 0   | F17b: `LinksChecker.php:144` `$path[0]` after `current(explode('#', $path, 2))`. Fix (verified): `explode('#', $path, 2)[0]`                                                                                                                                                                                                                                                                                                            |
| possibly-null-array-index             | 1     | Warning | 0   | 0   | 1   | 0   | 0   | F18: `DefenceBeforeFixDeclarationTest.php:181`                                                                                                                                                                                                                                                                                                                                                                                          |
| redundant-type-comparison             | 1     | Help    | 0   | 0   | 0   | 1   | 0   | C1: `src/Helper.php:45` `!\is_string($_SERVER['PWD'])`, which PHPStan requires (`return.type` without it). The local-variable form is accepted by both                                                                                                                                                                                                                                                                                  |

Totals: 72 A, 73 B, 63 Bw, 2 C, 1 D, making 211.

### Notes on single findings

- **F8 `paradoxical-condition`** reproduces on an exact copy of the function with fully typed
  inputs (`fx/Paradox.php`), so it is not part of the F2 cascade. Mago's note says that after
  `\in_array($receiver, $afterStops, true)` is false, "`$receiver is not string` must be true".
  That inference is wrong: a string that is not in one list says nothing about its type. This is
  a Mago bug that can be reported upstream. Two rewrites of the flag update did not clear it.
- **F22** is Mago lacking phpstan-phpunit's knowledge of `createStubFor…` intersections. With
  `@phpstan-require-extends TestCase` or `@require-extends TestCase` on the trait, the
  `non-existent-method` becomes `invalid-return-statement` (`Stub` against `Countable&Stub`), so
  the finding remains in another form.
- **F12** is cleared only by `/** @var list<string> $names */` on the initialiser, or by not using
  `array_pop`. `array_slice($names, 0, -1)` did not clear it.
- **F14 in `InfectionToolTest`:** inlining the array at the 10 call sites would repeat the literals
  `'auto'` and `'infectionDiffBase'` and trip `phpqaci.repeatedStringLiteral`. No clean workaround
  was found there, so those findings are B. In `PhpInvoker`, inlining `[self::XDEBUG_MODE => 'off']`
  was verified clean in both tools.
- **D, `list-destructure-string-key`:** tightening the docblock to `array{string, string, string}`
  clears Mago in a fixture. Whether the callers' types satisfy PHPStan is not known. To settle it,
  tighten the docblock on a scratch copy of `LinksChecker.php` and run PHPStan on that copy.

## Admission table (for D4, on the record; D4 is deferred by the D1 ruling)

"Admit-able later" means zero findings on R after the code fixes, and no C:

- **yes:** the code's findings are all A.
- **yes (Bw):** the code needs Mago-workaround rewrites as well, each verified on a fixture.
- **no:** a B without a verified workaround remains, or a C remains.

A code admitted at Help or Warning level would also need `minimum-fail-level` lowered, or a
per-code level raised, before it could block anything.

| code                                  | count | A   | B         | C   | D   | admit-able later?                                                      |
| ------------------------------------- | ----- | --- | --------- | --- | --- | ---------------------------------------------------------------------- |
| mixed-argument                        | 73    | 0   | 73 (46 w) | 0   | 0   | no (27 F2 cascade)                                                     |
| reference-to-undefined-variable       | 30    | 30  | 0         | 0   | 0   | yes (Help level)                                                       |
| less-specific-argument                | 15    | 0   | 15        | 0   | 0   | no                                                                     |
| less-specific-nested-argument-type    | 14    | 0   | 14 (4 w)  | 0   | 0   | no                                                                     |
| possibly-undefined-variable           | 14    | 14  | 0         | 0   | 0   | yes (Warning level)                                                    |
| invalid-argument                      | 8     | 1   | 7         | 0   | 0   | no by code; yes only with a Mago `patches` stub (config, Owner call)   |
| possibly-invalid-argument             | 8     | 6   | 2 (1 w)   | 0   | 0   | no (F9 const)                                                          |
| invalid-destructuring-source          | 6     | 0   | 6         | 0   | 0   | no                                                                     |
| invalid-type-cast                     | 6     | 6   | 0         | 0   | 0   | yes (Warning level)                                                    |
| less-specific-nested-return-statement | 5     | 1   | 4 (4 w)   | 0   | 0   | yes (Bw)                                                               |
| mixed-return-statement                | 3     | 0   | 3 (2 w)   | 0   | 0   | no (F22)                                                               |
| possible-method-access-on-null        | 3     | 3   | 0         | 0   | 0   | yes                                                                    |
| incompatible-parameter-name           | 2     | 2   | 0         | 0   | 0   | yes (Warning; by the SplHeap-free redesign)                            |
| invalid-return-statement              | 2     | 2   | 0         | 0   | 0   | yes                                                                    |
| mixed-operand                         | 2     | 1   | 1         | 0   | 0   | no (F2)                                                                |
| non-existent-method                   | 2     | 1   | 1         | 0   | 0   | no (F22)                                                               |
| possibly-false-operand                | 2     | 1   | 1 (1 w)   | 0   | 0   | yes (Bw; Warning)                                                      |
| possibly-null-argument                | 2     | 0   | 2 (2 w)   | 0   | 0   | yes (Bw)                                                               |
| possibly-null-operand                 | 2     | 2   | 0         | 0   | 0   | yes (Warning)                                                          |
| redundant-logical-operation           | 2     | 0   | 1         | 1   | 0   | no (C1, F21)                                                           |
| avoid-catching-error                  | 1     | 0   | 1         | 0   | 0   | no                                                                     |
| docblock-type-mismatch                | 1     | 0   | 1 (1 w)   | 0   | 0   | yes (Bw)                                                               |
| invalid-property-assignment-value     | 1     | 0   | 1         | 0   | 0   | no (only an inline `@var` clears it)                                   |
| list-destructure-string-key           | 1     | 0   | 0         | 0   | 1   | unknown until D is settled                                             |
| missing-template-parameter            | 1     | 1   | 0         | 0   | 0   | yes (SplHeap-free redesign; the `@extends` route hits a C1-class trap) |
| paradoxical-condition                 | 1     | 0   | 1         | 0   | 0   | no                                                                     |
| possibly-false-argument               | 1     | 1   | 0         | 0   | 0   | yes                                                                    |
| possibly-false-array-access           | 1     | 0   | 1 (1 w)   | 0   | 0   | yes (Bw; Warning)                                                      |
| possibly-null-array-index             | 1     | 0   | 1 (1 w)   | 0   | 0   | yes (Bw; Warning)                                                      |
| redundant-type-comparison             | 1     | 0   | 0         | 1   | 0   | no while the I4 C1 probe stands (R can be cleared by the local form)   |

The B column includes the Bw findings; "(n w)" gives how many of them have a verified workaround.

On R, then, 15 of the 30 codes reach zero, covering 73 findings. Seven get there by A fixes alone
(`reference-to-undefined-variable`, `possibly-undefined-variable`, `invalid-type-cast`,
`possible-method-access-on-null`, `invalid-return-statement`, `possibly-false-argument`,
`possibly-null-operand`), and two more by A fixes through the SplHeap-free redesign
(`incompatible-parameter-name`, `missing-template-parameter`). The other six also need Bw
workarounds (`less-specific-nested-return-statement`, `possibly-false-operand`,
`possibly-null-argument`, `docblock-type-mismatch`, `possibly-false-array-access`,
`possibly-null-array-index`). Of the remaining 15 codes, 14 cannot reach zero without a Mago fix,
a Mago `patches` stub, an inline `@var` or an Owner-approved ignore, and one
(`list-destructure-string-key`) is D.

## Recommendations (separate from the facts above)

1. **Admit only codes that reach zero by A fixes.** For D4, start from
   `possibly-undefined-variable`, `invalid-type-cast`, `possible-method-access-on-null`,
   `invalid-return-statement`, `possibly-false-argument` and `possibly-null-operand`. Each needs
   its P± pair (I3). Do not admit Bw-only codes: their fixes exist to work around Mago bugs, and
   new code will hit the same bugs again.
2. **Do the F1 initialisation fix regardless.** It is free, both tools accept it, and it is
   already the house form in `LinksChecker.php:319`. It removes 91 findings, or 43% of the total.
   Whether to do it now is a D10-style code-change decision, not an admission.
3. **Report F2 and F8 upstream, with fixtures, rather than working around them.** F2 is the
   template-ordering bug; F8 is the `in_array` negative-narrowing bug. Until F2 is fixed, every
   PHPStan collector rule, and many consumers' PHPStan extensions, will produce `mixed` cascades.
   The `patches` route is a config-level suppression and is the Owner's call under DBF.
4. **Narrow C1 to the construct, not the codes.** In AGREEMENT.md, give C1 a P± pair: the guarded
   superglobal form is P+ and must not be flagged (I4), and the local-variable form is the fix
   both tools accept. Add the `SplHeap` `@extends` trap as a corpus case. It is a second instance
   of "the fix Mago's message leads to trips PHPStan", the C13 class.
5. **Treat 1.57.0 as the measured baseline only.** These counts are for 1.57.0. Re-run the
   classifier (`classify.php` over a fresh `flat.tsv`) on each Mago bump before D4 is taken up;
   families F2, F8, F11, F12, F14 and F21 are Mago inference gaps that a release may close or
   widen.
