# OPcache

**Identifier**: `phpqaci.opcache`

An always-on check that compiles every checked PHP file through OPcache and asserts the
resulting bytecode is free of the OPcache defects php-qa-ci knows about. One lane covers all of
them, because they share the compile; a further defect of the same kind becomes another
assertion here rather than another tool (see
[CLAUDE/tool-boundaries.md](../../CLAUDE/tool-boundaries.md)).

## What is checked

### A comparison the optimizer left constant-vs-constant

The compiler folds a comparison of two literals itself, so the VM ships no handler for that
operand pair. On an affected PHP the optimizer's data-flow pass can nevertheless produce one:
it proves a variable holds a constant on some branch, substitutes the constant into a later
comparison of that variable with a literal, and then fails to fold the result. The handler the
specialiser picks reads the first operand as a variable slot, so the comparison reads whatever
memory that slot points at — a segmentation fault when the address is unmapped, a silent
comparison against garbage when it is not.

The trigger is ordinary code. A null check followed, on the same path, by a comparison of the
same variable with an array literal is enough, and several plain idioms compile to it:

```php
if (null !== $x || [] !== $x) { ... }
if ($x === null) { return $x === [1] ? 'eq' : 'ne'; }
if ($x === null) { return $x == [] ? 'eq' : 'ne'; }
```

Which of them the optimizer mangles depends on what it can prove, so there is no syntactic rule
to follow and no way to tell by reading. Compiling the code and looking at the opcodes is the
only reliable check, which is what this lane does.

The evidence, the core dumps, the opcode dumps and the idiom matrix are recorded in
[Plan 00007](../../CLAUDE/Plan/00007-opcache-optimizer-const-comparison-crash/investigation.md).

## How it runs

- In the full pipeline, in the linting phase straight after PHP Lint.
- Standalone: `vendor/bin/qa -t oc`; supports `-p <path>`.
- Skipped, with a note, on a PHP outside the affected range, and on a host whose CLI has no
  OPcache to compile through.
- Compiles each file with `opcache_compile_file()` through
  [`bin/opcache-optimizer-dump`](../../bin/opcache-optimizer-dump), 200 files per process.
  Nothing is executed, so a bootstrap or a `bin/` script is as safe to check as a class.
- Passes `opcache.optimization_level` explicitly at PHP's default rather than inheriting the
  host's. A host that has applied the mitigation below would otherwise compile nothing wrong
  and the lane would go blind exactly where the operator did the right thing; the verdict has
  to be about what an unmitigated host would compile.
- Passes `opcache.file_update_protection=0`, because OPcache does not cache (and so never
  optimises or dumps) a file written in the last couple of seconds. The fixers rewrite files
  moments earlier in the same run, so leaving it on would turn a just-fixed file into a silent
  pass.
- Fails as a crash if any file produced no dump at all. Nothing checked such a file, and a pass
  on an unchecked file is the one verdict this lane must never give; the usual causes are an
  `opcache.blacklist_filename` entry and exhausted shared memory.

## How to fix a failure

Each finding names the file, the enclosing function's line range (the dump carries no per-op
line), the function, and the opcode as dumped:

```
/app/src/Gateway/CreditNoteGateway.php:129-167  App\Gateway\CreditNoteGateway::find  IS_NOT_IDENTICAL null array(...)
```

Rewrite the condition so the second comparison does not sit on the branch where the first has
already decided the value. For the shape above, `null === $x || [] === $x` in that order
compiles cleanly, because the short-circuit means the array comparison is only reached where
the variable is not null. Then re-run the lane and confirm.

Never suppress this. A finding is a real crash, or a real silent wrong answer, on any host
running an affected PHP with the default optimizer mask.

## Mitigating it host-side as well

The defect lives in one optimizer pass, so clearing that pass removes the whole class of it.
On a host that must run an affected PHP, set this in `php.ini` for **every** SAPI, not only the
CLI, because real code compiles through the same optimizer under a web SAPI:

```ini
opcache.optimization_level=0x7FFEBFDF
```

That is PHP's default mask (`0x7FFEBFFF` — not every bit set) with the data-flow bit
cleared. OPcache still caches compiled bytecode and still runs every other pass; what is
lost is the modest gain from inferred constants and narrowed types.

If the host already sets a non-default mask, clear the bit from **that** value rather than
pasting the line above, or the other passes it turned off come back on:

```bash
php -r 'printf("opcache.optimization_level=0x%X\n", intval(ini_get("opcache.optimization_level"), 16) & ~0x20);'
```

The start-up advisory prints exactly this, computed from the running host's own mask.

The pipeline warns at start-up when the running PHP is in the affected range and that pass is
still on. It warns rather than fails, because a project may run QA on a host it does not
administer, and this lane is the gate that matters either way.

## Implementation

- Lane: [`OpcacheTool`](../../src/Pipeline/Lane/OpcacheTool.php).
- Dump parser: [`DumpParser`](../../src/Pipeline/Lane/Opcache/DumpParser.php).
- Known defects, the affected ranges, the mask and the start-up advisory:
  [`OpcacheDefects`](../../src/Pipeline/Config/OpcacheDefects.php).
