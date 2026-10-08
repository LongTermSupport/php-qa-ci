<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use UnexpectedValueException;

/**
 * The committed record of which Turbo binaries go with the shipped phpstan.phar:
 * `vendor-phar/turbo-ext.json`, holding the PHPStan version (which is also the phpstan/turbo-ext
 * release tag) and the SHA-256 of every Linux and macOS asset of that release, as GitHub publishes
 * them. An install downloads only the host's asset and refuses one whose digest differs, so the
 * binary a consumer runs is the one this file pinned when the phar was updated.
 *
 * @internal
 */
final readonly class TurboManifest
{
    /** Relative to the library root. */
    public const string PATH = 'vendor-phar/turbo-ext.json';

    /** How GitHub's release API prefixes an asset digest. */
    private const string SHA256_PREFIX = 'sha256:';

    /** A SHA-256 as stored here: 64 lowercase hex digits, no prefix. */
    private const string SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    /** Windows builds: upstream names them by Visual Studio version, and the lane does not run there. */
    private const string WINDOWS_MARKER = '-vs1';

    /** @param array<string, string> $assets asset name => lowercase hex SHA-256 */
    public function __construct(
        public string $phpstanVersion,
        public array $assets,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = \Safe\json_decode($json, true);
        if (!\is_array($data) || !\is_string($data['phpstan'] ?? null) || '' === $data['phpstan']) {
            throw new UnexpectedValueException(self::PATH . ' has no "phpstan" version');
        }

        $assets = $data['assets'] ?? [];
        if (!\is_array($assets)) {
            throw new UnexpectedValueException(self::PATH . ' "assets" is not an object');
        }

        $checked = [];
        foreach ($assets as $name => $digest) {
            if (!\is_string($name) || !\is_string($digest) || 1 !== \Safe\preg_match(self::SHA256_PATTERN, $digest)) {
                throw new UnexpectedValueException(\sprintf('%s asset "%s" does not carry a SHA-256 digest', self::PATH, $name));
            }

            $checked[$name] = $digest;
        }

        return new self($data['phpstan'], $checked);
    }

    /** From GitHub's release API response for the phpstan/turbo-ext tag. */
    public static function fromReleaseApi(string $json): self
    {
        $release = \Safe\json_decode($json, true);
        if (!\is_array($release) || !\is_string($release['tag_name'] ?? null) || !\is_array($release['assets'] ?? null)) {
            throw new UnexpectedValueException('the phpstan/turbo-ext release response has no tag or assets');
        }

        $assets = [];
        foreach ($release['assets'] as $asset) {
            if (!\is_array($asset) || !\is_string($asset['name'] ?? null) || !\is_string($asset['digest'] ?? null)) {
                continue;
            }

            if (str_contains($asset['name'], self::WINDOWS_MARKER) || !str_starts_with($asset['digest'], self::SHA256_PREFIX)) {
                continue;
            }

            $assets[$asset['name']] = substr($asset['digest'], \strlen(self::SHA256_PREFIX));
        }

        return new self($release['tag_name'], $assets);
    }

    public function digestFor(string $assetName): ?string
    {
        return $this->assets[$assetName] ?? null;
    }

    public function toJson(): string
    {
        $assets = $this->assets;
        ksort($assets);

        return \Safe\json_encode(['phpstan' => $this->phpstanVersion, 'assets' => $assets], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }
}
