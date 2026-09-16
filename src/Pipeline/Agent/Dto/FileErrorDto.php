<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent\Dto;

use JsonSerializable;

/**
 * One finding against one file, as an agent-mode report records it.
 *
 * Every field an analyser may omit is nullable and serialises as null rather
 * than as a zero or an empty string, so a consumer reading the JSON can tell
 * "PHPStan gave no line for this" from "line 0".
 *
 * @internal
 */
final readonly class FileErrorDto implements JsonSerializable
{
    public function __construct(
        public ?int $line,
        public string $message,
        public ?string $identifier,
        public ?string $tip,
    ) {
    }

    /** @return array{line: ?int, message: string, identifier: ?string, tip: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'line'       => $this->line,
            'message'    => $this->message,
            'identifier' => $this->identifier,
            'tip'        => $this->tip,
        ];
    }
}
