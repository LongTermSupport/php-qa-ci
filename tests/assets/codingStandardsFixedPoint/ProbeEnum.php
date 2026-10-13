<?php

declare(strict_types=1);

namespace Probe;

enum ProbeEnum: string
{
    /** First. */
    case Alpha = 'alpha';

    /** Second. */
    case Beta = 'beta';

    public function isAlpha(): bool
    {
        return self::Alpha === $this;
    }
}
