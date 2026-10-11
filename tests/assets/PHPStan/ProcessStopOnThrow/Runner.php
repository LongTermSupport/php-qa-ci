<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

/** Shares Process's method names and is not a Process. */
final class Runner
{
    public function run(callable $callback): void
    {
        $callback('line');
    }

    public function start(): void
    {
    }

    public static function fromShellCommandline(string $command, ?string $cwd, ?array $env, mixed $input): void
    {
    }
}
