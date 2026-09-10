<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

/**
 * Typed access to the environment variables that configure a run. The names
 * are the CI-facing contract and are kept from the Bash pipeline.
 *
 * @internal
 */
final readonly class EnvironmentReader
{
    /** @param array<string, string> $env */
    public function __construct(private array $env)
    {
    }

    /** @return array<string, string> */
    public static function fromProcess(): array
    {
        return getenv();
    }

    public function string(string $name): ?string
    {
        $value = $this->env[$name] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }

        return $value;
    }

    /** A "1"/"true" is true, a "0"/"false" is false, anything else is the default. */
    public function bool(string $name, bool $default): bool
    {
        $value = $this->string($name);
        if (null === $value) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true'  => true,
            '0', 'false' => false,
            default      => $default,
        };
    }

    /** A "1"/"true" is true, a "0"/"false" is false, anything else (including absent) is null. */
    public function boolOrNull(string $name): ?bool
    {
        $value = $this->string($name);
        if (null === $value) {
            return null;
        }

        return match (strtolower($value)) {
            '1', 'true'  => true,
            '0', 'false' => false,
            default      => null,
        };
    }

    public function int(string $name, int $default): int
    {
        $value = $this->string($name);
        if (null === $value || 1 !== \Safe\preg_match('/^\d+$/', $value)) {
            return $default;
        }

        return (int)$value;
    }

    /**
     * Non-interactive mode: an explicit CI=true, a Claude Code session, or no
     * TTY on stdin/stdout.
     */
    public function isCi(bool $stdinIsTty, bool $stdoutIsTty): bool
    {
        if ($this->bool('CI', false)) {
            return true;
        }

        if ('1' === $this->string('CLAUDECODE')) {
            return true;
        }

        return !$stdinIsTty || !$stdoutIsTty;
    }

    /**
     * Read-only (verification) mode is orthogonal to CI: an explicit
     * QA_READONLY wins, else GitHub Actions is read-only, else writable.
     */
    public function isReadOnly(): bool
    {
        $explicit = $this->boolOrNull('QA_READONLY');
        if (null !== $explicit) {
            return $explicit;
        }

        return $this->bool('GITHUB_ACTIONS', false);
    }

    /** Aggregate mode is on for a read-only run unless QA_FAIL_FAST=1 forces fail-fast. */
    public function isAggregate(bool $readOnly): bool
    {
        return $readOnly && !$this->bool('QA_FAIL_FAST', false);
    }
}
