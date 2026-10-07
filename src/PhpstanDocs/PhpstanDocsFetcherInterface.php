<?php

declare(strict_types=1);

namespace LTS\PHPQA\PhpstanDocs;

use RuntimeException;

/**
 * Where PHPStan's identifier pages come from.
 *
 * @internal
 */
interface PhpstanDocsFetcherInterface
{
    /**
     * Places phpstan/phpstan's `website/errors/`, `website/src/errorsIdentifiers.json`
     * and `LICENSE`, as they are at the tip of its default branch, under $into,
     * which must not exist yet.
     *
     * @return string what was fetched, as `<branch>@<commit>`
     *
     * @throws RuntimeException when the pages cannot be fetched
     */
    public function fetch(string $into): string;
}
