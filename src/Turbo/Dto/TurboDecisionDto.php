<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo\Dto;

use LTS\PHPQA\Turbo\TurboActionEnum;

/**
 * One decision about the Turbo binary: what to do, and for a fetch which asset and digest.
 *
 * @internal
 */
final readonly class TurboDecisionDto
{
    /**
     * @param string|null $asset  the release asset to fetch; only set for TurboActionEnum::Fetch
     * @param string|null $digest its SHA-256; only set for TurboActionEnum::Fetch
     */
    public function __construct(
        public TurboActionEnum $action,
        public string $message,
        public ?string $asset = null,
        public ?string $digest = null,
    ) {
    }

    public static function ready(string $message): self
    {
        return new self(TurboActionEnum::Ready, $message);
    }

    public static function fetch(string $asset, string $digest, string $message): self
    {
        return new self(TurboActionEnum::Fetch, $message, $asset, $digest);
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
