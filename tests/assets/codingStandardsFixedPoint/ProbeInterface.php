<?php

declare(strict_types=1);

namespace Probe;

interface ProbeInterface
{
    public function first(): int;
    public function second(): int;
    public const int VERSION = 1;
}
