<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStream;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;

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
        $output = new StreamOutput($stream);
        $buffered = new BufferedOutput();
        error_log($message, 3, '/tmp/report.log');
        $lines = readlineCount($message);
    }

    /** @return list<string> */
    public function aStreamNameAsData(): array
    {
        $this->compare('php://stderr');

        return self::LOG_TARGETS;
    }

    private function compare(string $target): bool
    {
        return \in_array($target, self::LOG_TARGETS, true);
    }
}

/** Named like the readline functions, but reads nothing from the process. */
function readlineCount(string $text): int
{
    return substr_count($text, "\n");
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
