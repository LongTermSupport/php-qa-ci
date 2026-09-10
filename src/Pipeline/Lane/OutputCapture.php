<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The in-process checks under src/ report with echo. Run one inside an
 * output buffer and forward what it printed to the pipeline's decoration
 * output, so --json mode keeps stdout clean and every line goes through the
 * same console.
 *
 * @internal
 */
final readonly class OutputCapture
{
    /** @param callable(): int $check */
    public static function run(callable $check, OutputInterface $output): int
    {
        \Safe\ob_start();

        try {
            $exit = $check();
        } catch (Throwable $throwable) {
            self::flush($output);

            throw $throwable;
        }

        self::flush($output);

        return $exit;
    }

    private static function flush(OutputInterface $output): void
    {
        $captured = \Safe\ob_get_clean();
        if ('' !== $captured) {
            $output->write($captured, false, OutputInterface::OUTPUT_RAW);
        }
    }
}
