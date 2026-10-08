<?php

declare(strict_types=1);

namespace PhpstanOutcomes;

final class Finding
{
    public function run(): int
    {
        return phpstan_outcomes_no_such_function();
    }
}
