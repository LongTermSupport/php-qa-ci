<?php

declare(strict_types=1);

namespace PhpQaCiFixture\ReadonlyService;

use Symfony\Contracts\Service\ResetInterface;

final class Mailer
{
    public function send(): void
    {
    }
}

final class Pool implements ResetInterface
{
    public function reset(): void
    {
    }
}

final class Clock implements \Stringable
{
    public function __toString(): string
    {
        return 'now';
    }
}
