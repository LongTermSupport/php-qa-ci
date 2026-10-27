<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;

/**
 * Computes the daemon config text that carries the required handler settings.
 *
 * @internal
 */
interface RequiredHandlersEditorInterface
{
    public function apply(string $yaml): RequiredHandlersEditDto;
}
