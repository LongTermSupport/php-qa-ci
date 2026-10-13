<?php

declare(strict_types=1);

namespace Probe;

final class ProbeHolder
{
    private string $name = 'n';

    private const string PREFIX = 'p';

    public function label(): string
    {
        return self::PREFIX . $this->name;
    }
}
