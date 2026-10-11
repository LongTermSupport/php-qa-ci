<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStream;

final class Reportable
{
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
    }
}

function aFunction(string $message): void
{
    fwrite(STDERR, $message);
}
