<?php

declare(strict_types=1);

// The shape filed upstream as https://github.com/php/php-src/issues/23644.
//
// COMPILED ONLY, NEVER EXECUTED: on an affected PHP, running f(null) segfaults
// the interpreter. OpcacheDefectRangeTest compiles it through the optimizer
// and reads the opcode dump to learn whether the running PHP still has the
// defect, so OpcacheDefects::FIRST_FIXED cannot drift from reality.

function gh23644(mixed $x): int
{
    if (null !== $x || [] !== $x) {
        return 1;
    }

    return 2;
}
