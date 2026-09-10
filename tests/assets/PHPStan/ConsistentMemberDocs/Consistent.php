<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ConsistentMemberDocs;

final class Consistent
{
    /** Kept for one release after the rename. */
    public const string OLD_NAME = 'old';

    /** The name every new caller uses. */
    public const string NEW_NAME = 'new';

    private int $retries = 0;

    private string $label = '';

    public function __construct(
        public ?float $timeout,
        public string $cwd,
    ) {
    }

    /** Methods are not a checked kind: one documented, one not, is fine. */
    public function retries(): int
    {
        return $this->retries;
    }

    public function label(): string
    {
        return $this->label;
    }
}
