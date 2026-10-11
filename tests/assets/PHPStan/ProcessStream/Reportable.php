<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStream;

use Symfony\Component\Console\Output\ConsoleOutput;

final class Reportable
{
    private const string NAMED_ELSEWHERE = 'the variable below holds its name';

    public function constants(string $message): void
    {
        fwrite(STDERR, $message);
        \Safe\fwrite(\STDOUT, $message);
        $line = fgets(STDIN);
        $closure = static fn (): bool => fflush(\STDERR);
    }

    /** @param resource $stream */
    public function aDefaultIsStillTheProcessStream($stream = \STDERR): void
    {
    }

    public function opens(string $message): void
    {
        $stderr = fopen('php://stderr', 'w');
        $stdout = \Safe\fopen('PHP://STDOUT', 'w');
        file_put_contents('php://stderr', $message);
        $stdin = file_get_contents('php://stdin');
        $fd = new \SplFileObject('php://fd/3', 'w');
        $target = 'php://stderr';
        $held = fopen($target, 'w');
    }

    public function reachesItWithoutNamingIt(string $message): void
    {
        $output = new ConsoleOutput();
        $answer = readline('> ');
        error_log($message);
        error_log($message, 4);
    }
}

function aFunction(string $message): void
{
    fwrite(STDERR, $message);
}
