<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lock\Dto;

use RuntimeException;

/**
 * @internal
 */
final readonly class LockInfoDto
{
    public function __construct(
        public string $hostname,
        public int $pid,
        public string $tool,
        public string $path,
        /** Unix timestamp. */
        public int $startedAt,
        /** Unix timestamp. */
        public int $lastActivity,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $decoded = \Safe\json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new RuntimeException('Lock file is not a JSON object');
        }

        return new self(
            hostname: self::string($decoded, 'hostname'),
            pid: self::int($decoded, 'pid'),
            tool: self::string($decoded, 'tool'),
            path: self::string($decoded, 'path'),
            startedAt: self::int($decoded, 'started_at'),
            lastActivity: self::int($decoded, 'last_activity'),
        );
    }

    public function toJson(): string
    {
        return \Safe\json_encode([
            'hostname'      => $this->hostname,
            'pid'           => $this->pid,
            'tool'          => $this->tool,
            'path'          => $this->path,
            'started_at'    => $this->startedAt,
            'last_activity' => $this->lastActivity,
        ], \JSON_PRETTY_PRINT) . "\n";
    }

    /** @param array<mixed> $decoded */
    private static function string(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /** @param array<mixed> $decoded */
    private static function int(array $decoded, string $key): int
    {
        $value = $decoded[$key] ?? null;

        return \is_int($value) ? $value : 0;
    }
}
