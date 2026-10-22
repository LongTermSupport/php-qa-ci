<?php

declare(strict_types=1);

namespace Named\Classes;

/** A second class-like, a trait. */
trait Greets
{
}

final class WithTrait
{
    use Greets;
}
