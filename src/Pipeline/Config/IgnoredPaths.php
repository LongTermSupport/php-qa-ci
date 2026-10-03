<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;

/**
 * The project's withIgnoredPaths() entries, resolved against the project root
 * once, for every lane that has to hand them to a tool or skip them itself.
 *
 * A path contains itself and everything below it, compared a whole segment at
 * a time, so `tests/assets` never swallows `tests/assetsExtra`. Each lane turns
 * these into whatever its tool accepts; the lane pages under docs/tools/ say
 * how.
 *
 * @internal
 */
final readonly class IgnoredPaths
{
    /** @var list<string> absolute, without a trailing slash */
    public array $absolute;

    public function __construct(string $projectRoot, string ...$relative)
    {
        $root           = rtrim($projectRoot, '/');
        $this->absolute = array_values(array_map(static function (string $path) use ($root): string {
            $path = trim($path);
            if (str_starts_with($path, './')) {
                $path = substr($path, 2);
            }

            $path = trim($path, '/');

            return '' === $path ? $root : $root . '/' . $path;
        }, $relative));
    }

    public static function of(QaConfigDto $config): self
    {
        return new self($config->paths->projectRoot, ...$config->pathsToIgnore);
    }

    public function isEmpty(): bool
    {
        return [] === $this->absolute;
    }

    public function contains(string $absolutePath): bool
    {
        return array_any(
            $this->absolute,
            static fn (string $ignored): bool => $absolutePath === $ignored || str_starts_with($absolutePath, $ignored . '/'),
        );
    }
}
