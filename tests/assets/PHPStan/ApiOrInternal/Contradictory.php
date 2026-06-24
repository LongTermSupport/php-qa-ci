<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiOrInternal;

/**
 * @api
 *
 * @internal
 */
final class Contradictory
{
    // Declares BOTH @api and @internal — contradictory.
    // RequireApiOrInternalTagRuleTest asserts the Both error at line 12, so do
    // NOT reformat the docblock or move the declaration.
}
