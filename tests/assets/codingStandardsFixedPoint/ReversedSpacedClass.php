<?php

declare(strict_types=1);

namespace Probe;

use Stringable;

final class ReversedSpacedClass implements Stringable
{
    private function hidden(): int
    {
        return $this->count + self::LIMIT + self::BASE;
    }

    protected function shared(): int
    {
        return $this->hidden();
    }

    public function visible(): int
    {
        return $this->shared();
    }

    public static function make(): self
    {
        return new self();
    }

    public function __toString(): string
    {
        return $this->label;
    }

    public function __destruct()
    {
        $this->count = 0;
    }

    public function __construct()
    {
        $this->count = 1;
    }

    /** Counted. */
    private int $count = 0;

    public string $label = 'reversed';

    private const int LIMIT = 2;

    /** Base. */
    public const int BASE = 1;

    use ProbeTrait;
}
