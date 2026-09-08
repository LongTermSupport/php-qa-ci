<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

/**
 * The lanes php-qa-ci ships, keyed by canonical registry name. A lane is
 * added here as it is ported; the locator refuses a name that is not here.
 *
 * @internal
 */
final readonly class ShippedTools
{
    /** @return array<string, ToolInterface> */
    public static function all(): array
    {
        return [];
    }
}
