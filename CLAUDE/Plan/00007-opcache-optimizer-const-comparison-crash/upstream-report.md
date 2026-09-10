# Upstream php-src report, ready to file

**Status**: drafted, not filed. To be filed by the owner under a separate agent/GitHub
identity (Plan 00007 Task 3.1). Record the issue number here once it exists.

**Where**: <https://github.com/php/php-src/issues/new?template=bug_report.yml>

Everything below is safe to post: no private repository, host, package or consumer is
named, and the reproduction is dependency-free.

---

## Title

Optimizer leaves a constant-vs-constant comparison unfolded, crashing the VM in `zval_undefined_cv`

## PHP version

8.5.10 (also expected on earlier 8.5.x; not verified on 8.4)

## Operating system

Linux x86_64 (Fedora/EL9 build, NTS)

## Description

The DFA/SCCP optimizer pass can substitute a variable it has proved constant into a later
comparison with a literal, and then leave the resulting constant-vs-constant opcode
unfolded. `ZEND_IS_NOT_IDENTICAL` and friends are declared
`ZEND_VM_HOT_NOCONSTCONST_HANDLER`, so no CONST,CONST handler exists; the specialiser
selects a handler that reads op1 as a TMP/VAR/CV slot. The operand is a constant index,
so the read lands outside the frame: a segmentation fault when the address is unmapped, a
silent comparison against unrelated memory when it is not.

JIT is off (`opcache.jit=0`, `opcache.jit_buffer_size=0`); this is the plain VM.

### Reproduction

```php
<?php
declare(strict_types=1);

function f(?array $x): string
{
    if (null !== $x || [] !== $x) {
        return 'out';
    }

    return 'in';
}

var_dump(f(null));
```

```
php -d opcache.enable_cli=1 -d opcache.opt_debug_level=0x20000 f.php
```

### Expected

`f()` compiles to a comparison against the variable, or the comparison is folded at
compile time. Either way no comparison opcode has two constant operands, because the VM
has no handler for that pair.

### Actual

The after-optimizer dump contains:

```
0000 CV0($x) = RECV 1
0001 T2 = TYPE_CHECK (null) CV0($x)
0002 JMPZ T2 0004
0003 T2 = IS_NOT_IDENTICAL null array(...)
0004 ...
```

`op[3]` has `op1_type=IS_CONST` and `op2_type=IS_CONST`. Executing that opcode reads
`EX_VAR(<constant index>)`.

Observed in production as five identical segfaults in the CLI SAPI, all at the same
instruction. Backtrace (with debug symbols for the same build):

```
#0  zval_undefined_cv (var=<optimized out>) at Zend/zend_execute.c:280
#1  _get_zval_cv_lookup                        Zend/zend_execute.c:303
#2  _get_zval_ptr_cv_deref                     Zend/zend_execute.c:341
#3  _get_zval_ptr_tmpvarcv                     Zend/zend_execute.c:409
#4  ZEND_IS_NOT_IDENTICAL_EMPTY_ARRAY_SPEC_TMPVARCV_CONST_JMPZ_HANDLER () at Zend/zend_vm_execute.h:14374
#5  execute_ex
```

Line 280 is the variable-name lookup inside the "Undefined variable" warning:

```c
zend_string *cv = CV_DEF_OF(EX_VAR_TO_NUM(var));
zend_error_unchecked(E_WARNING, "Undefined variable $%S", cv);
```

With `var = 0xfffff8c0` the computed address, `op_array.vars[(var >> 4) - 5]`, matched the
faulting address in the kernel log exactly, confirming the constant operand is being read
as a variable slot.

Whether a given instance faults or merely misbehaves depends on where that address lands,
so the silent-wrong-answer case is the more dangerous one: several ordinary idioms produce
the same opcode and return a plausible value.

### Optimizer pass

Clearing bit `0x20` of `opcache.optimization_level` (the DFA pass, which carries SCCP)
removes the bad opcode:

| `opcache.optimization_level` | comparisons left constant-vs-constant |
| ---------------------------- | ------------------------------------- |
| `0x7FFEBFFF` (the default)   | 1                                     |
| `0x7FFEBFDF` (DFA cleared)   | 0                                     |
| `0`                          | 0                                     |

### Other shapes that produce it

All with an earlier check that proves the variable's value on the branch:

```php
if (null !== $x || [] !== $x) { ... }
if ($x === null) { return $x === [1] ? 'eq' : 'ne'; }
if ($x === null) { return $x == [] ? 'eq' : 'ne'; }
```

These do not, in the same test:

```php
if ($x === null) { return [] === $x ? 'eq' : 'ne'; }
if (null === $x) { if ([] === $x) { ... } }
if ($x === null || $x === []) { ... }
```

### Ruled out

JIT (off), Xdebug (not loaded in the crashing processes), OOM (no `oom_kill` in the
cgroup counters), and the FPM SAPI (CLI only). The crash reproduces from the snippet
above with no extensions beyond OPcache.
