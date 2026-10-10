<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use UnexpectedValueException;

/**
 * The committed record of which Turbo files go with the shipped phpstan.phar:
 * `vendor-phar/turbo-ext.json`, holding the PHPStan version (which is also the phpstan/phpstan tag
 * the files are read from) and the SHA-256 of every Linux and macOS file under that tag's
 * `turbo-ext/`: each PHP version's binary and the platform's shared core the binary loads. An
 * install downloads only the host's pair and refuses a file whose digest differs, so what a
 * consumer runs is what this file pinned when the phar was updated.
 *
 * @internal
 */
final readonly class TurboManifest
{
    /** Relative to the library root. */
    public const string PATH = 'vendor-phar/turbo-ext.json';

    /** A SHA-256 as stored here: 64 lowercase hex digits, no prefix. */
    private const string SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    /** Where the repository keeps the files the phar loads. */
    private const string REPOSITORY_DIRECTORY = 'turbo-ext/';

    /**
     * A Unix binary or core under `turbo-ext/`, relative to it: `<platform>/phpstan_turbo-8.5.so`,
     * `<platform>/phpstan_turbo-8.5-zts.so` or `<platform>/phpstan_turbo_core.so`. The Windows
     * builds are DLLs in their own directory, and the lane does not run there.
     */
    private const string REPOSITORY_FILE_PATTERN = '#^(?!windows-)[a-z0-9_-]+/phpstan_turbo(?:-\d+\.\d+(?:-zts)?|_core)\.so$#';

    /** @param array<string, string> $files path under `turbo-ext/` => lowercase hex SHA-256 */
    public function __construct(
        public string $phpstanVersion,
        public array $files,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = \Safe\json_decode($json, true);
        if (!\is_array($data) || !\is_string($data['phpstan'] ?? null) || '' === $data['phpstan']) {
            throw new UnexpectedValueException(self::PATH . ' has no "phpstan" version');
        }

        $files = $data['files'] ?? [];
        if (!\is_array($files)) {
            throw new UnexpectedValueException(self::PATH . ' "files" is not an object');
        }

        $checked = [];
        foreach ($files as $path => $digest) {
            if (!\is_string($path) || !\is_string($digest) || 1 !== \Safe\preg_match(self::SHA256_PATTERN, $digest)) {
                throw new UnexpectedValueException(\sprintf('%s file "%s" does not carry a SHA-256 digest', self::PATH, $path));
            }

            $checked[$path] = $digest;
        }

        return new self($data['phpstan'], $checked);
    }

    /**
     * The Turbo files, relative to `turbo-ext/`, in GitHub's git-tree API response for the
     * phpstan/phpstan tag.
     *
     * @return list<string>
     */
    public static function pathsInRepositoryTree(string $json): array
    {
        $tree = \Safe\json_decode($json, true);
        if (!\is_array($tree) || !\is_array($tree['tree'] ?? null)) {
            throw new UnexpectedValueException('the phpstan/phpstan tree response has no entries');
        }

        if (true === ($tree['truncated'] ?? null)) {
            throw new UnexpectedValueException('the phpstan/phpstan tree response is truncated, so a manifest from it would pin only some of the Turbo files');
        }

        $paths = [];
        foreach ($tree['tree'] as $entry) {
            if (!\is_array($entry) || !\is_string($entry['path'] ?? null) || 'blob' !== ($entry['type'] ?? null)) {
                continue;
            }

            if (!str_starts_with($entry['path'], self::REPOSITORY_DIRECTORY)) {
                continue;
            }

            $relative = substr($entry['path'], \strlen(self::REPOSITORY_DIRECTORY));
            if (1 === \Safe\preg_match(self::REPOSITORY_FILE_PATTERN, $relative)) {
                $paths[] = $relative;
            }
        }

        if ([] === $paths) {
            throw new UnexpectedValueException('the phpstan/phpstan tag holds no Turbo files under turbo-ext/');
        }

        return $paths;
    }

    public function digestFor(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    /** Whether the manifest pins both files the phar needs to load Turbo on the host. */
    public function pinsBuildFor(TurboPlatform $platform): bool
    {
        $binary = $platform->binaryPath();
        $core   = $platform->corePath();

        if (null === $binary || null === $core) {
            return false;
        }

        return null !== $this->digestFor($binary) && null !== $this->digestFor($core);
    }

    public function toJson(): string
    {
        $files = $this->files;
        ksort($files);

        return \Safe\json_encode(['phpstan' => $this->phpstanVersion, 'files' => $files], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }
}
