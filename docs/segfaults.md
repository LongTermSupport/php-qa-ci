# Segmentation faults

A program that ends with exit code 139, `killed by signal 11 (SIGSEGV)`, crashed with a
segmentation fault. php-qa-ci names it that way wherever it reports how a child process ended,
and `vendor/bin/describe-exit <exit code>` gives the same description to a script of your own.

A segfault is a defect, never a failure to retry. It means the program, or the runtime under
it, executed something it had no handler for. The same defect that crashed one run can return
a wrong answer in another, where the bad address happens to land somewhere readable, so a
retry that passes proves nothing.

## Find what crashed

The process that died is the one the message names, but the crashing code is often below it:

- **The PHP engine.** Most segfaults under PHP are in the engine, OPcache or the JIT, not in
  the PHP code being run. Run the same command again with OPcache off (`-d opcache.enable_cli=0`)
  and with the JIT off (`-d opcache.jit=disable`): a crash that goes away with one of them is
  in that part of the engine. The [opcache lane](tools/opcache.md) detects a known OPcache
  defect of this kind in your own code.
- **An extension.** A native extension crashes the process that loaded it. `php -m` lists what
  is loaded. PHPStan's Turbo binary is one: a crash during analysis that goes away once
  `vendor/lts/php-qa-ci/vendor-phar/turbo-ext/` is removed is a Turbo defect.
- **The program itself.** Native programs crash in their own code.

The evidence is outside the process: the kernel log has a `segfault at <address> ip <address>`
line for each crash (`journalctl -k`, or `dmesg`), even when the command around it reported
success. In a container the kernel and its core dumps are the host's, so `coredumpctl` inside
the container is empty: ask whoever runs the host for the kernel log line and the core file.

## Reduce it, then report it where the code lives

Cut the input down to the smallest that still crashes, not merely the smallest that shows some
other symptom; a reproduction a maintainer can run is worth more than a backtrace.

Report it to the project whose code crashed:

- the PHP engine, OPcache or the JIT: [php-src](https://github.com/php/php-src/issues);
- an extension, or a tool such as PHPStan: that project's tracker;
- one of php-qa-ci's own helpers: [php-qa-ci](https://github.com/LongTermSupport/php-qa-ci/issues).
  A crash php-qa-ci reports in one of its own helpers names that tracker in the message.
