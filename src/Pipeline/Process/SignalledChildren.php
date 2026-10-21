<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Process;

use LTS\PHPQA\Pipeline\Process\Dto\SignalledChildDto;

/**
 * The children a signal killed since they were last taken. SymfonyProcessRunner
 * records each one; the executor takes them after every lane, so the lane that
 * ran one is reported as crashed whatever it made of the exit code, and the
 * pipeline takes them around the lanes, so a start-up probe or a hook that ran
 * one stops the run rather than going unnoticed.
 *
 * @internal
 */
final class SignalledChildren
{
    /** @var list<SignalledChildDto> */
    private array $children = [];

    public function record(SignalledChildDto $child): void
    {
        $this->children[] = $child;
    }

    /**
     * @return list<SignalledChildDto> every child recorded since the last take, oldest first
     */
    public function take(): array
    {
        $taken          = $this->children;
        $this->children = [];

        return $taken;
    }
}
