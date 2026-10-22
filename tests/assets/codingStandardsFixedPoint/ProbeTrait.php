<?php

declare(strict_types=1);

namespace Probe;

trait ProbeTrait
{
    public function traitValue(): string
    {
        return $this->traitName . self::TRAIT_SUFFIX;
    }
    protected string $traitName = 't';
    public const string TRAIT_SUFFIX = 's';
}
