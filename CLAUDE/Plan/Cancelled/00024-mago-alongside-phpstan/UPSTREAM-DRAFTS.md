# Plan 00024 — Upstream report drafts for Mago (unfiled)

These are drafts for `carthage-software/mago`. **None is filed.** Filing posts publicly under a
real identity and cannot be retracted, so each needs the Owner's authorisation per report
(`CLAUDE/segfault-policy.md` step 3; DECISIONS.md D12). Every claim below was measured on this
host; the scratch artefacts are under `untracked/scratch/mago-phase0/`.

## U1: segfault (exit 139) on a long binary-operator chain — the segfault-policy report

**Status:** debugged (policy step 2) to a minimal, deterministic reproduction; awaiting filing
authorisation (step 3).

Draft title: *Segfault (stack overflow, no message) on a long chain of a binary operator, e.g.
`'a' . 'a' . … ` with ~8,600 operands*

Draft body:

> **Version:** reproduced on 1.57.0 and 1.58.2, `x86_64-unknown-linux-musl` release binaries
> (1.58.2 tarball sha256 `dec1aec6…bfc22`, matching the release API digest), Linux x86_64.
>
> **Reproduction.** A file holding one statement `$x = 'a' . 'a' . … . 'a';` with N operands:
>
> ```bash
> n=8617
> { printf '<?php\n\n$x = '; for ((i = 1; i < n; i++)); do printf "'a' . "; done; printf "'a';\n"; } > chain.php
> mago lint chain.php      # Segmentation fault, exit 139, nothing on stdout or stderr
> ```
>
> **What was measured:**
>
> - With the default 8 MB stack (`ulimit -s 8192`) N = 8,524 passes and N = 8,617 crashes, on
>   both versions. With a 16 MB stack N = 16,000 passes and N = 18,000 crashes. The threshold
>   scales with the stack size, so this is stack exhaustion from recursion depth proportional to
>   the chain length.
> - With an unlimited stack N = 100,000 no longer crashes; `lint` then ran past a 120-second
>   timeout.
> - `mago ast chain.php` crashes too, already at N = 8,524, so the recursion is in the parser or
>   the AST walk, not in a lint rule. `mago analyze` crashes on the same file.
> - No `thread '…' has overflowed its stack` message is printed: the process dies with a bare
>   SIGSEGV, so a caller cannot tell it from any other crash.
> - PHP 8.5 accepts the same file (`php -l` and executing it, at N = 100,000).
>
> **Expected:** either the file is linted, or Mago fails with a diagnostic naming the file and the
> limit (it already does this for deeply nested parentheses: `error[parse]` past about 512
> levels), never a silent segfault. Generated code (templates, compiled containers, data
> fixtures) can produce long chains.

Our defence while it is open (policy step 4) is AGREEMENT.md I7 and corpus case C18: any exit
other than 0/1 with parseable output fails the run as a crash, never a pass; the wrapper and lane
must report a signal exit by name.

## U2: valid PHP rejected past ~512 nested parentheses (`error[parse]`)

From Task 0.2 (`261019-phase0-mago-behaviour.md`): parentheses nested 600 to 100,000 deep give
`error[parse]`, exit 1, where `php -l` accepts 1,000. A depth limit is reasonable; reporting it as
a PHP syntax error is not, because a caller cannot tell "your PHP is invalid" from "Mago gave
up". Ask for a distinct code (e.g. `nesting-limit`) and the limit in the message. Corpus case C17.

## U3: `CollectedDataNode::get()` template not resolved (classification family F2)

From Task 0.5 (`261019-phase0-analyser-classification.md`): 50 findings across two PHPStan rules
come from Mago not resolving the method-level template on PHPStan's
`PHPStan\Node\CollectedDataNode::get()`, so every collector's data becomes `mixed`. Needs a
standalone fixture (a stub of the class with the same docblock) before filing; the classifier's
scratch `fx/` holds the in-repo shape. Affects every project with PHPStan collector rules.

## U4: wrong negative narrowing after `\in_array(..., true)` (family F8)

`fx/Paradox.php` in the Task 0.5 scratch reproduces it with fully typed inputs: after
`\in_array($receiver, $list, true)` is false, Mago infers "`$receiver is not string`" and reports
`paradoxical-condition`. A string absent from one list says nothing about its type. Needs the
fixture reduced to a few lines before filing.
