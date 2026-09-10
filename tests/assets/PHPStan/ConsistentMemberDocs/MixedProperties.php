<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ConsistentMemberDocs;

final readonly class MixedProperties
{
    /** @var list<string> only a tag: not documentation */
    private array $tagsOnly;

    public function __construct(
        /** Seconds; null for no limit. */
        public ?float $timeout,
        public string $cwd,
        public bool $streamOutput,
    ) {
        $this->tagsOnly = [];
    }

    /** @return list<string> */
    public function tagsOnly(): array
    {
        return $this->tagsOnly;
    }
}
