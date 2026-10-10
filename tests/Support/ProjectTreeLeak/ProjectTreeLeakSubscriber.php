<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\ProjectTreeLeak;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/** After each test, including its tearDown(), charges what it wrote into the project tree. */
final readonly class ProjectTreeLeakSubscriber implements FinishedSubscriber
{
    public function __construct(private ProjectTreeLedger $ledger)
    {
    }

    public function notify(Finished $event): void
    {
        $this->ledger->sweep($event->test()->id());
    }
}
