<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;

/**
 * What `composer update <packages> --dry-run` printed, read three ways: the
 * named packages it would upgrade, a package source it could not reach (it
 * then answers from its cache and still exits 0), and, for a run that
 * failed, the one line that says why.
 *
 * @internal
 */
final readonly class UpdateDryRunOutput
{
    /** `  - Upgrading lts/php-qa-ci (dev-php8.5 93f1ae4 => dev-php8.5 606a9f6)`, as Composer 2 prints an update operation. */
    private const string UPGRADE = '/^-\s+Upgrading\s+(\S+)\s+\((.+?)\s+=>\s+(.+?)\)$/';

    /** `In VcsRepository.php line 193:`, the heading of the exception block Composer prints when it fails. */
    private const string EXCEPTION_HEADING = '/^\s*In \S+ line \d+:\s*$/';

    /**
     * Composer's wording when a repository could not be reached and its
     * answer came from the local cache.
     */
    private const array UNREACHABLE = ['could not be fully loaded', 'may be out of date', 'may be outdated', 'could not be accessed'];

    public function __construct(private string $output)
    {
    }

    /**
     * The upgrades of the named packages, each once (Composer lists an
     * operation for the lock and again for the vendor directory), in the
     * order Composer printed them.
     *
     * @return list<StaleDependencyDto>
     */
    public function staleAmong(string ...$packages): array
    {
        $wanted = array_map(strtolower(...), array_values($packages));
        $stale  = [];
        foreach ($this->lines() as $line) {
            if (1 !== \Safe\preg_match(self::UPGRADE, $line, $match) || !isset($match[1], $match[2], $match[3])) {
                continue;
            }

            $name = strtolower($match[1]);
            if (\in_array($name, $wanted, true) && !isset($stale[$name])) {
                $stale[$name] = new StaleDependencyDto($match[1], $match[2], $match[3]);
            }
        }

        return array_values($stale);
    }

    /** The line saying a package source could not be reached, or null when every source answered. */
    public function unreachableSource(): ?string
    {
        foreach ($this->lines() as $line) {
            $lower = strtolower($line);
            if (array_any(self::UNREACHABLE, static fn (string $marker): bool => str_contains($lower, $marker))) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Why a failed run failed: the message of the exception block, joined
     * onto one line, else the last line printed.
     */
    public function failureReason(): string
    {
        $lines   = explode("\n", $this->output);
        $message = [];
        $inBlock = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (!$inBlock) {
                $inBlock = 1 === \Safe\preg_match(self::EXCEPTION_HEADING, $line);

                continue;
            }

            if ('' !== $trimmed) {
                $message[] = $trimmed;

                continue;
            }

            if ([] !== $message) {
                return implode(' ', $message);
            }
        }

        if ([] !== $message) {
            return implode(' ', $message);
        }

        return array_last($this->lines()) ?? 'no output';
    }

    /** @return list<string> the non-blank lines, trimmed */
    private function lines(): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $this->output)), static fn (string $line): bool => '' !== $line));
    }
}
