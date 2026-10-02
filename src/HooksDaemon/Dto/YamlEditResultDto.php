<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon\Dto;

use LTS\PHPQA\HooksDaemon\YamlEditOutcomeEnum;

/**
 * The outcome of one edit and the resulting file text, which is the input
 * unchanged unless the outcome is Inserted or Updated.
 *
 * @internal
 */
final readonly class YamlEditResultDto
{
    public function __construct(
        public YamlEditOutcomeEnum $outcome,
        public string $yaml,
    ) {
    }
}
