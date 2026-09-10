<?php

declare(strict_types=1);

namespace LTS\PHPQA\ShellCheck\Dto;

use LTS\PHPQA\ShellCheck\InstallActionEnum;

/**
 * One decision about the vendored ShellCheck: what to do, which release it
 * concerns, and the line to print either way.
 *
 * @api
 */
final readonly class InstallDecisionDto
{
    /** @param string|null $version the release to fetch; only set for InstallActionEnum::Fetch */
    public function __construct(
        public InstallActionEnum $action,
        public string $message,
        public ?string $version = null,
    ) {
    }

    public static function ready(string $message): self
    {
        return new self(InstallActionEnum::Ready, $message);
    }

    public static function fetch(string $version, string $message): self
    {
        return new self(InstallActionEnum::Fetch, $message, $version);
    }

    public static function broken(string $message): self
    {
        return new self(InstallActionEnum::Broken, $message);
    }
}
