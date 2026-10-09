<?php

declare(strict_types=1);

namespace DiffScope;

/** No mutable code at all: Infection generates no mutant from it. */
interface Shape
{
    public const string UNIT = 'cm';

    public function area(): float;
}
