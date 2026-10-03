<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\TempLeak;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/** After each test, including its tearDown(), charges what it left behind. */
final readonly class TempLeakSubscriber implements FinishedSubscriber
{
    public function __construct(private TempLeakLedger $ledger)
    {
    }

    public function notify(Finished $event): void
    {
        $this->ledger->sweep($event->test()->id(), ...self::openFiles());
    }

    /**
     * The files this process holds open, read from /proc/self/fd; empty where there is no /proc.
     *
     * @return list<string>
     */
    public static function openFiles(): array
    {
        if (!is_dir('/proc/self/fd')) {
            return [];
        }

        $open = [];
        foreach (\Safe\scandir('/proc/self/fd') as $fd) {
            // The descriptor scandir() itself used is listed but already closed, so not a link.
            $link = '/proc/self/fd/' . (\is_string($fd) ? $fd : '');
            if (\is_string($fd) && ctype_digit($fd) && is_link($link)) {
                $target = \Safe\readlink($link);
                if (str_starts_with($target, '/')) {
                    $open[] = $target;
                }
            }
        }

        return $open;
    }
}
