<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto;

/**
 * A locked package `composer update` would move, with both versions as
 * Composer printed them: `dev-php8.5 93f1ae4` for a branch, `1.2.0` for a tag.
 *
 * @internal
 */
final readonly class StaleDependencyDto
{
    public function __construct(
        public string $name,
        public string $locked,
        public string $available,
    ) {
    }

    /** "lts/php-qa-ci (locked dev-php8.5 93f1ae4, available dev-php8.5 606a9f6)". */
    public function describe(): string
    {
        return \sprintf('%s (locked %s, available %s)', $this->name, $this->locked, $this->available);
    }
}
