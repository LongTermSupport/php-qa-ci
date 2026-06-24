<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiOrInternal;

final class Unclassified
{
    // No @api / @internal docblock — a library public class-like that MUST be
    // classified. RequireApiOrInternalTagRuleTest asserts the Missing error at
    // line 7, so do NOT add a class docblock or move the declaration.
}
