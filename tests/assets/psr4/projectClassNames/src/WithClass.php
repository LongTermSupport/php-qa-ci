<?php

declare(strict_types=1);

namespace Named\Classes;

final class WithClass
{
    public function helper(): WithClassHelper
    {
        return new WithClassHelper();
    }
}

/** A second class beside the one the path names. */
final class WithClassHelper
{
}
