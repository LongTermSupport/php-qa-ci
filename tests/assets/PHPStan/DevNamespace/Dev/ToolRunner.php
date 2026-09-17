<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DevNamespace\Dev;

/**
 * A `Dev` segment directly beneath the shipped root, rather than nested under
 * another segment: the rule must not depend on how deep the segment sits.
 */
final class ToolRunner
{
    public function run(): void
    {
    }
}
