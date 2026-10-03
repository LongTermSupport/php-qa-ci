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
        $this->ledger->sweep($event->test()->id());
    }
}
