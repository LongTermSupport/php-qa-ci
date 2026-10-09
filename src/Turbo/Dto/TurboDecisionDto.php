<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo\Dto;

use LTS\PHPQA\Turbo\TurboActionEnum;

/**
 * One decision about the Turbo files: what to do, and for a fetch which files and digests.
 *
 * @internal
 */
final readonly class TurboDecisionDto
{
    /**
     * @param array<string, string> $files path under `turbo-ext/` => SHA-256, in the order to place them;
     *                                     only set for TurboActionEnum::Fetch
     */
    public function __construct(
        public TurboActionEnum $action,
        public string $message,
        public array $files = [],
    ) {
    }

    public static function ready(string $message): self
    {
        return new self(TurboActionEnum::Ready, $message);
    }

    /** @param array<string, string> $files path under `turbo-ext/` => SHA-256 */
    public static function fetch(array $files, string $message): self
    {
        return new self(TurboActionEnum::Fetch, $message, $files);
    }

    public static function unsupported(string $message): self
    {
        return new self(TurboActionEnum::Unsupported, $message);
    }

    public static function broken(string $message): self
    {
        return new self(TurboActionEnum::Broken, $message);
    }
}
