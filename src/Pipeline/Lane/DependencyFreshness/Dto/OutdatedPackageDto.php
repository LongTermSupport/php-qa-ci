<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto;

/**
 * A locked package `composer outdated` lists, with both versions as Composer
 * printed them (`v0.2.1`, `dev-main abc1234`).
 *
 * @internal
 */
final readonly class OutdatedPackageDto
{
    public function __construct(
        public string $name,
        public string $version,
        public string $latest,
    ) {
    }
}
