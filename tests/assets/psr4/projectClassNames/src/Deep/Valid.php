<?php

declare(strict_types=1);

namespace Named\Classes\Deep;

use Countable;

/** The one class-like its path names; the anonymous class inside it has no name to load. */
final class Valid
{
    public function counter(): Countable
    {
        return new class implements Countable {
            public function count(): int
            {
                return 0;
            }
        };
    }
}
