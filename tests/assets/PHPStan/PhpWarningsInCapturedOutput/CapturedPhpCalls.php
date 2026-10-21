<?php

declare(strict_types=1);

namespace PhpQaCiFixture\PhpWarningsInCapturedOutput;

use Composer\Util\ProcessExecutor;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use Symfony\Component\Process\Process;

final readonly class CapturedPhpCalls
{
    private const string PHP = 'php';

    public function __construct(private string $phpBinPath, private ProcessExecutor $executor)
    {
    }

    /** @param list<string> $wrapper */
    public function reported(string $script, array $wrapper, ?string $configured): void
    {
        new Process([\PHP_BINARY, $script]);
        new Process(['php', '-r', 'echo 1;']);
        new Process(['/usr/bin/php8.5', $script]);
        new Process([self::PHP, '-d', 'memory_limit=1G', $script]);
        new Process([...$wrapper, \PHP_BINARY, $script]);
        new Process(command: [$this->phpBinPath, $script]);
        Process::fromShellCommandline('php ' . escapeshellarg($script));
        Process::fromShellCommandline(escapeshellarg(\PHP_BINARY) . " {$script} --version");
        new ProcessSpecDto([$this->phpBinPath, '-r', 'echo 1;'], '/tmp');
        $output = null;
        $this->executor->execute('php ' . escapeshellarg($script) . ' --version', $output);
        new ProcessExecutor()->execute(escapeshellarg(\PHP_BINARY) . ' x', $output);
        $command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script) . ' state';
        $this->executor->execute($command, $output);
        $argv = [\PHP_BINARY, $script];
        new Process($argv);
        new Process([$configured ?? 'php', $script]);
        $this->executor->execute(output: $output, command: 'php x');
    }

    /** @param list<string> $argv */
    public function notReported(string $script, string $command, array $argv): void
    {
        $output = null;
        new Process([\PHP_BINARY, '-d', 'display_errors=stderr', $script]);
        new Process(['php', '-ddisplay_errors=0', $script]);
        new Process(['php', '-d', 'display_errors=Off', $script]);
        Process::fromShellCommandline('php -d display_errors=stderr ' . escapeshellarg($script));
        $this->executor->execute(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script) . ' install');
        $routed = escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script);
        $this->executor->execute($routed, $output);
        $later = escapeshellarg(\PHP_BINARY) . ' x';
        $later = 'git status';
        $this->executor->execute($later, $output);
        $appended = escapeshellarg(\PHP_BINARY);
        $appended .= ' -d display_errors=stderr x';
        $this->executor->execute($appended, $output);
        $this->executor->execute($command, $output);
        new Process($argv);
        new Process(['git', 'log', 'php']);
        new Process(['phpunit']);
        new Process(['php-cs-fixer', 'fix']);
        Process::fromShellCommandline('echo php');
        new ProcessSpecDto(['git', 'status'], '/tmp');
    }
}
