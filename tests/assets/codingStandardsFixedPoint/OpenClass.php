<?php

declare(strict_types=1);

namespace Probe;

class OpenClass
{
    protected function doubled(): int
    {
        return self::FACTOR * $this->value;
    }

    public function value(): int
    {
        return $this->doubled();
    }

    protected int $value = 1;

    protected static ?self $instance = null;

    final protected const int FACTOR = 2;
}
