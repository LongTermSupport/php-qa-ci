<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\OutdatedPackageDto;

/**
 * What `composer outdated --locked --format=json` printed on stdout: an
 * object whose `locked` list holds each outdated package. A `locked` list is
 * the positive sign that Composer answered, an empty one meaning nothing is
 * outdated; any other text (a shell shim's script, an HTML error page) is no
 * answer.
 *
 * @internal
 */
final readonly class OutdatedOutput
{
    private const string LOCKED = 'locked';

    /** @var list<mixed>|null the `locked` list, null when the text is no answer */
    private ?array $entries;

    public function __construct(string $stdout)
    {
        $decoded       = json_validate($stdout) ? \Safe\json_decode($stdout, true) : null;
        $locked        = \is_array($decoded) ? $decoded[self::LOCKED] ?? null : null;
        $this->entries = \is_array($locked) && array_is_list($locked) ? $locked : null;
    }

    public function answered(): bool
    {
        return null !== $this->entries;
    }

    /** @return list<OutdatedPackageDto> lower-case names; an entry lacking a name, version or newest version is left out */
    public function packages(): array
    {
        $packages = [];
        foreach ($this->entries ?? [] as $entry) {
            $name    = \is_array($entry) ? $entry['name']    ?? null : null;
            $version = \is_array($entry) ? $entry['version'] ?? null : null;
            $latest  = \is_array($entry) ? $entry['latest']  ?? null : null;
            if (\is_string($name) && \is_string($version) && \is_string($latest)) {
                $packages[] = new OutdatedPackageDto(strtolower($name), $version, $latest);
            }
        }

        return $packages;
    }
}
