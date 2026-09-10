<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

/**
 * What the configured PHP binary reports about OPcache: whether the
 * extension is loaded at all, and `opcache.optimization_level` exactly as the
 * ini holds it (hex, decimal or empty), left for OpcacheDefects to parse.
 *
 * @api
 */
final readonly class OpcacheSettingsDto
{
    public function __construct(
        public bool $loaded,
        public string $optimizationLevel,
    ) {
    }
}
