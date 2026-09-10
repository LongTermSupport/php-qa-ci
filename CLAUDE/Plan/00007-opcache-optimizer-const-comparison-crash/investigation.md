# Investigation dossier: PHP 8.5.10 segfault in `zval_undefined_cv`

Everything below was observed on 2026-09-09 and 2026-09-10 on one development
container. It is a record of that moment, kept as written.

## Environment

| Item     | Value                                                                                                                                          |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP      | 8.5.10 CLI, NTS, remi build `php-cli-8.5.10-1.module_php.8.5.el9.remi.x86_64`                                                                  |
| OPcache  | statically built in; `opcache.enable_cli=1`, default `opcache.optimization_level` (`0x7FFEBFFF`), `opcache.jit=0`, `opcache.jit_buffer_size=0` |
| Xdebug   | 3.5.3, `xdebug.mode=debug`, `start_with_request=yes` (not loaded in the crashing processes, see below)                                         |
| Host     | LXC container; the kernel and `systemd-coredump` are the host's                                                                                |
| Consumer | a private library whose full pipeline includes Infection with 11 threads                                                                       |

## Symptom

The host kernel log recorded five segfaults, all in the CLI `php` binary at the
same instruction offset:

```
php[2439620]: segfault at c2114af0 ip ... in php[239fd0,...+601000]
php[2818634]: segfault at c32e1af0 ...
php[1837918]: segfault at c24baaf0 ...
php[1424293]: segfault at c26148f0 ...
php[2396740]: segfault at c2fb18f0 ...
```

Nothing inside the container recorded them: `coredumpctl` was empty because the
core pattern pipes to the host's `systemd-coredump`, php-fpm's log showed only
clean restarts, and every pipeline that contained a crash exited 0.

## What was ruled out, and how

| Hypothesis                                                      | Test                                                                                               | Result                                                                                                                                         |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| php-fpm crash                                                   | fpm error log, `NRestarts`                                                                         | no child ever exited on a signal                                                                                                               |
| OOM                                                             | cgroup `memory.events`, `systemd-oomd` journal                                                     | zero `oom_kill`                                                                                                                                |
| JIT                                                             | ini                                                                                                | JIT off                                                                                                                                        |
| A debugger attaching (Xdebug `start_with_request=yes`)          | fake DBGp listener accepting every session, 10 filtered Infection runs                             | no crash; mutants do not even load Xdebug (Infection's Xdebug-handler restart sets `PHPRC` to a temp ini without it and `PHP_INI_SCAN_DIR=""`) |
| A specific mutant, standalone                                   | 30 filtered runs (960 mutants) and 6 full runs (3,204 mutants) under `strace -f -e signal=SIGSEGV` | zero signals                                                                                                                                   |
| Xdebug loaded in `off` mode (the pipeline's launch environment) | 3 full runs with `XDEBUG_MODE=off`                                                                 | zero signals                                                                                                                                   |
| The complete pipeline                                           | 2 full pipelines under strace                                                                      | zero signals                                                                                                                                   |

The standalone runs never crashed because they were run in a different consumer
from the one whose mutant crashes. The cores settled that.

## The cores

Exported on the host with `coredumpctl dump`, read in the container with gdb plus
`php-debuginfo` and `php-debugsource` for the same build. All five are the same
process shape:

```
#0  zval_undefined_cv (var=<optimized out>) at Zend/zend_execute.c:280
#1  _get_zval_cv_lookup ...                    zend_execute.c:303
#2  _get_zval_ptr_cv_deref ...                 zend_execute.c:341
#3  _get_zval_ptr_tmpvarcv ...                 zend_execute.c:409
#4  ZEND_IS_NOT_IDENTICAL_EMPTY_ARRAY_SPEC_TMPVARCV_CONST_JMPZ_HANDLER () at zend_vm_execute.h:14374
#5  execute_ex ...
```

`argv` in every core:

```
php <consumer>/bin/phpunit --configuration .../var/qa/infection/tmp/infection/phpunitConfiguration.306085584257a414fcb459ad989248a8.infection.xml
```

The same mutant hash all five times. `EG(current_execute_data)->func` is a valid
user method (a private finder in a gateway class, lines 129 to 167 of its file);
the low addresses like `0x42fba7d8` are OPcache shared memory, not corruption.

Line 280 is:

```c
zend_string *cv = CV_DEF_OF(EX_VAR_TO_NUM(var));
zend_error_unchecked(E_WARNING, "Undefined variable $%S", cv);
```

Disassembly at the fault: `shr edi,4; lea edx,[rdi-5]; mov rax,[rax+0x80]; mov rdx,[rax+rdx*8]`,
which is `op_array.vars[(var >> 4) - 5]` with `var = 0xfffff8c0`. The vars table is at
`0x42fb1cb8`; `0x42fb1cb8 + 8 * ((0xfffff8c0 >> 4) - 5) = 0xc2fb18f0`, the exact
faulting address in the kernel line for that core.

## The op_array

Dumped from the core with a gdb Python snippet (`untracked/scratch/crash/dump-ops.py`
in the consumer's checkout). The mutated line compiled to:

```
op[51] line=158 opcode=123 (TYPE_CHECK)       op1_type=CV    op1=0x90  ext=0x2 (null)
op[52] line=158 opcode=43  (JMPNZ)            op1_type=TMP
op[53] line=158 opcode=17  (IS_NOT_IDENTICAL) op1_type=CONST op1=0xfffff8c0  op2_type=CONST op2=0xfffff8f0
op[54] line=158 opcode=43  (JMPNZ)
```

Both operands of the comparison are constants. `ZEND_IS_NOT_IDENTICAL` is declared
`ZEND_VM_HOT_NOCONSTCONST_HANDLER`: the VM ships no CONST,CONST handler because the
compiler is expected to fold that pair. The handler the specialiser selected treats
op1 as a TMP/VAR/CV slot, so it reads `EX_VAR(0xfffff8c0)`, finds garbage, treats it
as undefined, and looks up a variable name that cannot exist.

## The source shape

Original line (compiles to `IS_IDENTICAL CV($creditNotes) array(...)`, correct):

```php
$creditNotes = $holder->getCreditNote();   // ?array

if (null === $creditNotes || [] === $creditNotes) {
    return null;
}
```

Infection `LogicalOrAllSubExprNegation` mutant (compiles to
`IS_NOT_IDENTICAL null array(...)`, the crash):

```php
if (!(null === $creditNotes) || !([] === $creditNotes)) {
    return null;
}
```

Optimizer dump (`opcache.opt_debug_level=0x20000`) of the mutated file:

```
0051 T5 = TYPE_CHECK (null) CV4($creditNotes)
0052 JMPZ T5 0055
0053 T5 = IS_NOT_IDENTICAL null array(...)
0054 JMPZ T5 0056
0055 RETURN null
0056 T6 = COUNT null
```

On the branch where the null check succeeded, the DFA pass substituted `null` for the
variable (also in the unreachable `COUNT`) and left the comparison unfolded. It
executes whenever the getter returns null, which the killing test does.

## Which optimizer setting removes it

| `opcache.optimization_level`                     | bad comparisons in the mutated file |
| ------------------------------------------------ | ----------------------------------- |
| `0x7FFEBFFF` (PHP's default)                     | 1                                   |
| `0x7FFEBFDF` (bit `0x20`, the DFA pass, cleared) | 0                                   |
| `0x7FFFBFFF` (default plus pass `0x10000`)       | 1                                   |
| `0`                                              | 0                                   |

PHP's default is `0x7FFEBFFF`, not every bit set: it ships passes `0x4000` and
`0x10000` off. Read it from the binary rather than assuming, since the recommended
value is derived from it:

```
php -n -r 'echo ini_get("opcache.optimization_level");'
```

The third row is the same measurement taken with `0x10000` additionally on, which
is what an invented "all bits" mask gives. It reproduces too, so the extra pass is
not implicated — but a detector that compiles with it is reporting bytecode no
production host produces, which is why the lane uses the real default.

## Hand-written idioms

Eight small functions of the form `function f(?array $x): string { ... }`, compiled
with the default mask and run for `null`, `[]`, `[1]`:

| Body                                                   | const-const opcode                          | ran                        |
| ------------------------------------------------------ | ------------------------------------------- | -------------------------- |
| `if ($x === null) { return [] === $x ? "eq" : "ne"; }` | no                                          | fine                       |
| `if (null === $x) { if ([] === $x) {...} }`            | no                                          | fine                       |
| `if (!(null === $x) \|\| !([] === $x)) {...}`          | no in this small file, yes in the real file | fine                       |
| `if (null !== $x \|\| [] !== $x) {...}`                | **yes**                                     | printed the expected value |
| `if ($x === null \|\| $x === []) {...}`                | no                                          | fine                       |
| `if ($x === null) { return $x === [1] ? ... }`         | **yes**                                     | printed the expected value |
| `if ($x === null) { return $x == [] ? ... }`           | **yes**                                     | printed the expected value |
| `if (null === $x) { return count($x); }`               | no                                          | TypeError, as it should    |

Three ordinary idioms produce the bad opcode. They did not crash because the bogus
slot happened to land inside the frame; they compared garbage and got lucky. There is
no clean syntactic rule for the trigger; it is whatever the DFA pass can prove.

## Exposure in real code

Every PHP file under `src/` of three consumers (1,095 files) was compiled through the
optimizer with the default mask and the dump grepped for a comparison opcode with
two constant operands: zero hits. Exposure at the time of writing is mutants only.

## Why the pipelines stayed green

Infection treats any non-zero exit of the test process as a killed mutant. A
segfault is exit 139, so the mutant was recorded as "killed by test framework" with
no output, and the MSI was one false kill higher. Nothing in the pipeline reads the
kernel log.

## Dependency-free reproduction of the opcode

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
```

```
php -d opcache.enable_cli=1 -d opcache.opt_debug_level=0x20000 f.php 2>&1 | grep IS_NOT_IDENTICAL
0003 T2 = IS_NOT_IDENTICAL null array(...)
```

Whether that particular file crashes or misbehaves depends on where the constant
lands relative to the frame; the opcode is the defect either way.

## Upstream

No matching php-src issue was found. The tracker has segfaults at the same site
only under the tracing JIT (issues 16009 and 20890). 8.5.10 was the newest release.
The report is deferred by the owner's ruling (Plan Task 3.1).
