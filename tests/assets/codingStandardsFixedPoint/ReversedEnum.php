<?php

declare(strict_types=1);

namespace Probe;

enum ReversedEnum: int implements ProbeInterface
{
    public static function fromName(string $name): self
    {
        return 'one' === $name ? self::One : self::Two;
    }
    public function first(): int
    {
        return self::VERSION;
    }
    public function second(): int
    {
        return self::DEFAULT;
    }
    case One = 1;
    case Two = 2;
    private const int DEFAULT = 2;
}
