<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Opcache\Dto;

/**
 * One comparison opcode the optimizer left with two constant operands: the
 * file and function it sits in, the function's line range (the after-optimizer
 * dump carries no per-op line), and the opcode line as dumped.
 *
 * @api
 */
final readonly class ConstComparisonDto
{
    public function __construct(
        public string $file,
        public string $function,
        public int $lineStart,
        public int $lineEnd,
        public string $comparison,
    ) {
    }

    public function display(): string
    {
        return \sprintf('%s:%d-%d  %s  %s', $this->file, $this->lineStart, $this->lineEnd, $this->function, $this->comparison);
    }
}
