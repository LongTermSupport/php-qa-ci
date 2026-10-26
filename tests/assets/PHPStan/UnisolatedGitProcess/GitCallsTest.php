<?php

declare(strict_types=1);

namespace PhpQaCiFixture\UnisolatedGitProcess;

use Symfony\Component\Process\Process;

final class GitCallsTest
{
    private const array ISOLATED = [
        'GIT_CONFIG_GLOBAL'  => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_COUNT'   => '2',
        'GIT_CONFIG_KEY_0'   => 'maintenance.auto', 'GIT_CONFIG_VALUE_0' => 'false',
        'GIT_CONFIG_KEY_1'   => 'gc.auto', 'GIT_CONFIG_VALUE_1' => '0',
    ];

    private const array WITHOUT_MAINTENANCE = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];

    private const array ONE_KEY_ONLY = [
        'GIT_CONFIG_GLOBAL'  => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_COUNT'   => '1', 'GIT_CONFIG_KEY_0' => 'gc.auto', 'GIT_CONFIG_VALUE_0' => '0',
    ];

    private const string GIT = 'git';

    /** @param list<string> $args */
    public function reported(array $args, string $cwd): void
    {
        new Process(['git', 'commit', '-m', 'x'], $cwd);
        new Process(['git', ...$args], $cwd, ['PATH' => '/usr/bin']);
        new Process(['/usr/bin/git', 'status'], $cwd, ['GIT_CONFIG_GLOBAL' => '/dev/null']);
        new Process([self::GIT, 'status'], $cwd, null);
        $command = ['git', 'log'];
        new Process($command, $cwd);
        Process::fromShellCommandline('git commit -m x', $cwd);
        Process::fromShellCommandline('GIT_DIR=.git git status', $cwd, ['GIT_CONFIG_NOSYSTEM' => '1']);
        new Process(command: ['git', 'status'], cwd: $cwd);
        \proc_open(['git', 'status'], [], $pipes, $cwd);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $unknown
     */
    public function reportedWhenTheEnvironmentIsNotKnown(array $args, array $unknown): void
    {
        new Process(['git', ...$args], null, $unknown);
    }

    /** @param list<string> $args */
    public function notReported(array $args, string $cwd): void
    {
        new Process(['git', ...$args], $cwd, self::ISOLATED);
        new Process(['git', 'status'], $cwd, [...self::ISOLATED, 'PATH' => '/usr/bin']);
        new Process(['git', 'status'], env: self::ISOLATED, cwd: $cwd);
        Process::fromShellCommandline('git status', $cwd, self::ISOLATED);
        \proc_open(['git', 'status'], [], $pipes, $cwd, self::ISOLATED);
        new Process(['php', '-v'], $cwd);
        new Process(['gitk'], $cwd);
        new Process($args, $cwd);
        Process::fromShellCommandline('echo git', $cwd);
    }

    public function reportedWhenAutoMaintenanceIsNotSwitchedOff(string $cwd): void
    {
        new Process(['git', 'commit', '-m', 'x'], $cwd, self::WITHOUT_MAINTENANCE);
        new Process(['git', 'commit', '-m', 'x'], $cwd, self::ONE_KEY_ONLY);
    }
}
