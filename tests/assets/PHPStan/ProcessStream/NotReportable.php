<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStream;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class NotReportable
{
    /** Infection accepts these as log targets, so they are data here, never opened. */
    private const array LOG_TARGETS = ['php://stderr', 'php://stdout'];

    /** @param resource $stream */
    public function handedAStream($stream, string $message): void
    {
        fwrite($stream, $message);
        echo $message;
        print $message;
        $memory = fopen('php://memory', 'w+');
        $file = fopen('/tmp/report.txt', 'w');
        $target = self::LOG_TARGETS[0];
        $opened = fopen($target, 'w');
    }
}

final class RunnerExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        register_shutdown_function(static function (): void {
            fwrite(\STDERR, 'the runner reports' . \PHP_EOL);
        });
    }
}
