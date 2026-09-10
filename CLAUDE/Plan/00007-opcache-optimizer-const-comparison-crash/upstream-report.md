# Upstream php-src report, ready to file

**Status**: drafted, not filed. To be filed by the owner under a separate agent/GitHub
identity (Plan 00007 Task 3.1). Record the issue number here once it exists.

**Where**: <https://github.com/php/php-src/issues/new?template=bug_report.yml>

**How php-src wants it filed** — required fields, house style, the LLM-disclosure rule
and what a follow-up PR would need — is
[Plan 00009's filing guide](../00009-upstream-php-src-bug-report-opcache-const-comparison/filing-guide.md).
Read that before pasting this.

Everything below is safe to post: no private repository, host, package or consumer is
named, and the reproduction is dependency-free.

The three `##` sections below map onto the three fields of the issue form. Inside
**Description**, the first three blocks are the form's own pre-filled skeleton; keep
them in that order. `php -v` and the OS must be the filer's own if they differ from
the values recorded here.

---

## Title

Optimizer leaves a constant-vs-constant comparison unfolded, crashing the VM in `zval_undefined_cv`

## PHP Version

```
PHP 8.5.10 (cli) (built: Aug 28 2026 07:27:07) (NTS)
Copyright (c) The PHP Group
Built by Debian
Zend Engine v4.5.10, Copyright (c) Zend Technologies
    with Zend OPcache v8.5.10, Copyright (c), by Zend Technologies
```

Not verified on 8.4 or on master.

## Operating System

Debian 12, x86_64

## Description

The following code:

```php
<?php
function f($x) {
    if (null !== $x || [] !== $x) { return 1; }
    return 2;
}
var_dump(f(null));
```

```
php -n -d opcache.enable_cli=1 -d opcache.file_update_protection=0 f.php
```

Resulted in this output:

```
Segmentation fault (core dumped)
```

But I expected this output instead:

```
int(1)
```

`-n` is there to show it needs nothing but OPcache; `file_update_protection=0` only
avoids waiting for the file to age past the default two seconds, without which OPcache
does not optimise it at all and the crash does not appear.

No 3v4l.org link: `opcache.enable_cli` is `INI_SYSTEM` and defaults to `0`, so a snippet
cannot turn the optimizer on from inside itself.

### Cause

The DFA/SCCP pass proves `$x` is `null` on the branch, substitutes the constant into the
later `[] !== $x`, and then leaves the resulting comparison with two constant operands.
The after-optimizer dump (`-d opcache.opt_debug_level=0x20000`):

```
f:
0000 CV0($x) = RECV 1
0001 T1 = TYPE_CHECK TYPE [bool, long, double, string, array, object, resource] CV0($x)
0002 JMPNZ T1 0005
0003 T1 = IS_NOT_IDENTICAL null array(...)
0004 JMPZ T1 0006
0005 RETURN int(1)
0006 RETURN int(2)
```

`op[3]` has `op1_type=IS_CONST` and `op2_type=IS_CONST`. `ZEND_IS_NOT_IDENTICAL` and its
neighbours are declared `ZEND_VM_HOT_NOCONSTCONST_HANDLER`, so no CONST,CONST handler
exists and the specialiser picks one that reads op1 as a TMP/VAR/CV slot. The operand is
a constant index, so the read lands outside the frame.

Backtrace (debug symbols for the same build):

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
faulting address in the kernel log exactly.

Whether an instance faults or silently compares unrelated memory depends on where that
address lands; some of the shapes below return a plausible value instead of crashing.

### Optimizer pass

Clearing bit `0x20` of `opcache.optimization_level` (the DFA pass, which carries SCCP)
removes the bad opcode and the snippet prints `int(1)`:

| `opcache.optimization_level` | result of the snippet |
| ---------------------------- | --------------------- |
| `0x7FFEBFFF` (the default)   | segfault              |
| `0x7FFEBFDF` (DFA cleared)   | `int(1)`              |
| `0`                          | `int(1)`              |

### Other shapes that produce a constant-vs-constant comparison

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

JIT (off; `-d opcache.jit=0 -d opcache.jit_buffer_size=0` changes nothing), Xdebug (`-n`
loads no php.ini, so no extensions beyond the built-in OPcache), OOM, and the FPM SAPI
(seen on CLI). The argument matters: `f([9])` never reaches the opcode and is fine.
