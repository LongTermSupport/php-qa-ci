<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Dto;

/**
 * What a range's `Changelog:` trailers say: whether one validly states that
 * no entry is needed, and the values refused for want of a usable reason.
 *
 * @api
 */
final readonly class ChangelogTrailerVerdictDto
{
    /** @param list<string> $invalid */
    public function __construct(
        public bool $optsOut,
        public array $invalid,
    ) {
    }
}
