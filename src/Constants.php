<?php

declare(strict_types=1);

namespace LTS\PHPQA;

final readonly class Constants
{
    /**
     * The key in $_SERVER that we check for in our PHPUnit tests.
     */
    public const string QA_QUICK_TESTS_KEY = 'phpUnitQuickTests';

    /**
     * The value for the key in $_SERVER that we check for in our PHPUnit tests. If equal to this value, we can skip
     * tests.
     */
    public const int QA_QUICK_TESTS_ENABLED = 1;
}
