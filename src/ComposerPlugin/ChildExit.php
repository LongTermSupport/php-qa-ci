<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

/**
 * Describes how a child process ended, from its exit code, for a line that reports its failure.
 *
 * A child killed by a signal prints nothing about its death, so a failure line that only points
 * at "the output above" sends the reader to output that does not exist. A shell, and Composer's
 * ProcessExecutor, report a signal kill as 128 + the signal's number; this names the signal, and
 * names a segmentation fault as a crash to report (CLAUDE/segfault-policy.md), never a failure to
 * retry. Only the signals numbered alike on Linux and macOS are named; any other is given by
 * number.
 *
 * It lives beside the Composer plugins, which call it mid-install, so it calls only global
 * functions (see ForbidNamespacedFunctionInComposerPluginRule). bin/describe-exit gives the
 * same description to the Bash scripts that relay a helper's exit.
 *
 * @internal
 */
final readonly class ChildExit
{
    /** A shell reports a child killed by signal n as this + n. */
    private const int SIGNAL_BASE = 128;

    /** The highest signal number Linux has (SIGRTMAX); a code past 128 + this is no signal. */
    private const int HIGHEST_SIGNAL = 64;

    /** Sent by the out-of-memory killer, so its name says so. */
    private const int SIGKILL = 9;

    /** A segmentation fault: a crash to report, never a failure to retry. */
    private const int SIGSEGV = 11;

    /** The signals whose numbers Linux and macOS share. */
    private const array SIGNAL_NAMES = [
        1             => 'SIGHUP',
        2             => 'SIGINT',
        3             => 'SIGQUIT',
        4             => 'SIGILL',
        5             => 'SIGTRAP',
        6             => 'SIGABRT',
        8             => 'SIGFPE',
        self::SIGKILL => 'SIGKILL, which is what the out-of-memory killer sends',
        self::SIGSEGV => 'SIGSEGV',
        13            => 'SIGPIPE',
        14            => 'SIGALRM',
        15            => 'SIGTERM',
    ];

    /** Where a crash is reported, so it is debugged and filed rather than retried past. */
    private const string SEGFAULT = ': it crashed with a segmentation fault, a defect to report at https://github.com/LongTermSupport/php-qa-ci/issues and never to retry past';

    /**
     * "exit code 1", or for a signal kill "exit code 143, killed by signal 15 (SIGTERM)".
     */
    public static function describe(int $exitCode): string
    {
        $description = 'exit code ' . $exitCode;
        if (!self::killedBySignal($exitCode)) {
            return $description;
        }

        $signal      = $exitCode - self::SIGNAL_BASE;
        $description .= ', killed by signal ' . $signal;
        if (\array_key_exists($signal, self::SIGNAL_NAMES)) {
            $description .= ' (' . self::SIGNAL_NAMES[$signal] . ')';
        }

        return self::SIGSEGV === $signal ? $description . self::SEGFAULT : $description;
    }

    /** True when the code is a shell's report of a signal kill, so the child said nothing of it. */
    public static function killedBySignal(int $exitCode): bool
    {
        return $exitCode > self::SIGNAL_BASE && $exitCode <= self::SIGNAL_BASE + self::HIGHEST_SIGNAL;
    }
}
