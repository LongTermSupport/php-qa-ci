<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerPlugin;

/**
 * Describes how a child process ended, from its exit code, for a line that reports its failure.
 *
 * A child killed by a signal prints nothing about its death, so a failure line that only points
 * at "the output above" sends the reader to output that does not exist. A shell, and Composer's
 * ProcessExecutor, report a signal kill as 128 + the signal's number; this names the signal, and
 * names a segmentation fault as a crash to report, never a failure to retry. A crash is reported
 * where the crashing code lives, so php-qa-ci's tracker is named only for php-qa-ci's own helpers;
 * any other program's crash points at docs/segfaults.md. Only the signals numbered alike on Linux
 * and macOS are named; any other is given by number.
 *
 * It lives beside the Composer plugins, which call it mid-install, so it calls only global
 * functions (see ForbidNamespacedFunctionInComposerPluginRule); that rule knows it as plugin code
 * by its namespace, which is why it stays here. bin/describe-exit gives the same description to
 * a script, php-qa-ci's own Bash scripts included, and the pipeline uses it for a lane's child
 * that a signal killed (SymfonyProcessRunner) and for a tool's crash summary.
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

    /** A crash of a program that is not php-qa-ci's: %s is the shipped segfault guide. */
    private const string SEGFAULT = 'it crashed with a segmentation fault, a defect to debug and report where the crashing code lives, never to retry past (see %s)';

    /** A crash of one of php-qa-ci's own helpers, so php-qa-ci's tracker is the place to report it. */
    private const string HELPER_SEGFAULT = 'it crashed with a segmentation fault, a defect to report at https://github.com/LongTermSupport/php-qa-ci/issues and never to retry past';

    /** A code a process exited with itself, in the signal range: %s is the signal, numbered and named. */
    private const string EXITED_ITSELF = ', which it exited with itself: the code a shell gives for signal %s';

    /** A process that exited 139 itself relays a crash below it: %s is the shipped segfault guide. */
    private const string RELAYED_SEGFAULT = ', so a process below it crashed with a segmentation fault, a defect to debug and report where the crashing code lives, never to retry past (see %s)';

    /** What every description begins with, before the code. */
    private const string EXIT_CODE = 'exit code ';

    /** bin/describe-exit's option saying the child is one of php-qa-ci's own helpers. */
    private const string OWN_HELPER_OPTION = '--php-qa-ci-helper';

    /** The segfault guide, relative to the package root. */
    private const string SEGFAULT_GUIDE = '/docs/segfaults.md';

    /**
     * "exit code 1", or for a signal kill "exit code 143, killed by signal 15 (SIGTERM)", for any
     * program: a segfault points at the guide to finding what crashed and where to report it.
     */
    public static function describe(int $exitCode): string
    {
        return self::EXIT_CODE . $exitCode . self::killedBy($exitCode);
    }

    /**
     * "exit code 1", or for a code a process exited with itself, which no signal killed, "exit code
     * 143, which it exited with itself: the code a shell gives for signal 15
     * (SIGTERM)". Only a caller that knows no signal killed the process can say so: a wrapper
     * relaying the crash of a process below it, as a shell does.
     */
    public static function describeExitedItself(int $exitCode): string
    {
        return self::EXIT_CODE . $exitCode . self::exitedItself($exitCode);
    }

    /**
     * What follows the exit code of a process no signal killed: "" outside the signal range, else
     * the signal a shell would mean by it, and for a segmentation fault that a process below it
     * crashed and what to do about it.
     */
    public static function exitedItself(int $exitCode): string
    {
        if (!self::killedBySignal($exitCode)) {
            return '';
        }

        $description = \sprintf(self::EXITED_ITSELF, self::signalName($exitCode - self::SIGNAL_BASE));

        return self::isSegmentationFault($exitCode) ? $description . \sprintf(self::RELAYED_SEGFAULT, self::segfaultGuide()) : $description;
    }

    /** As describe(), for one of php-qa-ci's own helpers: a segfault is php-qa-ci's to fix. */
    public static function describePhpQaCiHelper(int $exitCode): string
    {
        return self::EXIT_CODE . $exitCode . self::signalSuffix($exitCode, self::HELPER_SEGFAULT);
    }

    /**
     * bin/describe-exit's arguments, `[--php-qa-ci-helper] <exit code 0-255>`, described; null when
     * they are anything else.
     */
    public static function describeArguments(string ...$arguments): ?string
    {
        $ownHelper = self::OWN_HELPER_OPTION === ($arguments[0] ?? null);
        if ($ownHelper) {
            array_shift($arguments);
        }

        if (1 !== \count($arguments)) {
            return null;
        }

        $exitCode = filter_var($arguments[0], \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 255]]);
        if (!\is_int($exitCode)) {
            return null;
        }

        return $ownHelper ? self::describePhpQaCiHelper($exitCode) : self::describe($exitCode);
    }

    /**
     * How a signal ended a program that is not php-qa-ci's, to follow its exit code: "" when no
     * signal did, else ", killed by signal 15 (SIGTERM)", and for a segmentation fault what to do
     * about it.
     */
    public static function killedBy(int $exitCode): string
    {
        return self::signalSuffix($exitCode, self::segfaultAdvice());
    }

    /** What a segmentation fault of a program that is not php-qa-ci's calls for, and where the guide is. */
    public static function segfaultAdvice(): string
    {
        return \sprintf(self::SEGFAULT, self::segfaultGuide());
    }

    /** The segfault guide in the installed package: finding what crashed and where to report it. */
    public static function segfaultGuide(): string
    {
        return \dirname(__DIR__, 2) . self::SEGFAULT_GUIDE;
    }

    /** The exit code a shell reports for a child killed by this signal. */
    public static function exitCodeOfSignal(int $signal): int
    {
        return self::SIGNAL_BASE + $signal;
    }

    /** True when the code is a shell's report of a segmentation fault. */
    public static function isSegmentationFault(int $exitCode): bool
    {
        return self::exitCodeOfSignal(self::SIGSEGV) === $exitCode;
    }

    /** True when the code is a shell's report of a signal kill, so the child said nothing of it. */
    public static function killedBySignal(int $exitCode): bool
    {
        return $exitCode > self::SIGNAL_BASE && $exitCode <= self::SIGNAL_BASE + self::HIGHEST_SIGNAL;
    }

    private static function signalSuffix(int $exitCode, string $segfault): string
    {
        if (!self::killedBySignal($exitCode)) {
            return '';
        }

        $signal      = $exitCode - self::SIGNAL_BASE;
        $description = ', killed by signal ' . self::signalName($signal);

        return self::SIGSEGV === $signal ? $description . ': ' . $segfault : $description;
    }

    /** "15 (SIGTERM)", or the bare number for a signal numbered differently per platform. */
    private static function signalName(int $signal): string
    {
        return \array_key_exists($signal, self::SIGNAL_NAMES) ? $signal . ' (' . self::SIGNAL_NAMES[$signal] . ')' : (string)$signal;
    }
}
