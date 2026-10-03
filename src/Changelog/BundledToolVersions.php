<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use Closure;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use Safe\Exceptions\JsonException;

/**
 * The versions of the tools php-qa-ci ships, and the changelog entry naming
 * the ones a dependency update moved. Read from the files that pin them, never
 * from a tool's own `--version` banner, whose format no two tools share:
 *
 *  - phive.xml: each `<phar>`'s `installed` attribute, keyed by its name;
 *  - vendor-bin/shellcheck.version, as `shellcheck`;
 *  - build/<tool>/: each package composer.json requires (bar PHP and
 *    extensions) at the version composer.lock resolved, keyed by package.
 *
 * A source that is absent contributes nothing, so a tool added or removed in
 * the update reads as such; a build file that is not JSON is refused.
 *
 * @api
 */
final readonly class BundledToolVersions
{
    public const string PHIVE = 'phive.xml';

    private const string SHELLCHECK = 'shellcheck';

    /**
     * @param Closure(string): ?string $file a project-relative file's contents, or null when absent
     *
     * @return array<string, string> tool => version
     */
    public function read(Closure $file, string ...$buildDirectories): array
    {
        $phive    = $file(self::PHIVE);
        $versions = null === $phive ? [] : $this->phive($phive);

        $shellcheck = $file(ShellCheckBinary::VERSION_FILE);
        if (null !== $shellcheck && '' !== trim($shellcheck)) {
            $versions[self::SHELLCHECK] = trim($shellcheck);
        }

        foreach ($buildDirectories as $directory) {
            $manifest  = 'build/' . $directory . '/composer.json';
            $lock      = 'build/' . $directory . '/composer.lock';
            $versions += $this->build($this->decode($manifest, $file($manifest)), $this->decode($lock, $file($lock)));
        }

        return $versions;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     */
    public function entry(array $before, array $after): ?string
    {
        $changes = [];
        foreach ($after as $tool => $version) {
            if (!isset($before[$tool])) {
                $changes[] = \sprintf('%s %s (added)', $tool, $version);
            } elseif ($before[$tool] !== $version) {
                $changes[] = \sprintf('%s %s → %s', $tool, $before[$tool], $version);
            }
        }

        foreach (array_diff_key($before, $after) as $tool => $version) {
            $changes[] = \sprintf('%s %s (removed)', $tool, $version);
        }

        if ([] === $changes) {
            return null;
        }

        return '**Bundled tool versions updated** by the weekly dependency update: ' . implode('; ', $changes) . '.';
    }

    /** @return array<string, string> */
    private function phive(string $xml): array
    {
        $versions = [];
        foreach (\array_slice(explode('<phar', $xml), 1) as $chunk) {
            $tag = strstr($chunk, '>', true);
            if (false === $tag || 1 !== \Safe\preg_match('/^\s/', $tag)) {
                continue;
            }

            $name      = $this->attribute($tag, 'name');
            $installed = $this->attribute($tag, 'installed');
            if (null !== $name && null !== $installed) {
                $versions[$name] = $installed;
            }
        }

        return $versions;
    }

    private function attribute(string $tag, string $attribute): ?string
    {
        if (1 !== \Safe\preg_match('/\s' . $attribute . '="([^"]+)"/', $tag, $matches) || !isset($matches[1])) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param array<array-key, mixed> $manifest
     * @param array<array-key, mixed> $lock
     *
     * @return array<string, string>
     */
    private function build(array $manifest, array $lock): array
    {
        $require  = $manifest['require'] ?? null;
        $packages = $lock['packages']    ?? null;
        if (!\is_array($require) || !\is_array($packages)) {
            return [];
        }

        $versions = [];
        foreach ($packages as $package) {
            if (!\is_array($package) || !\is_string($package['name'] ?? null) || !\is_string($package['version'] ?? null)) {
                continue;
            }

            if (\array_key_exists($package['name'], $require)) {
                $versions[$package['name']] = $package['version'];
            }
        }

        return $versions;
    }

    /**
     * An absent file is no data; a file that is not JSON is a broken pin,
     * which must stop the update rather than drop the tool from its entry.
     *
     * @return array<array-key, mixed>
     */
    private function decode(string $path, ?string $json): array
    {
        if (null === $json) {
            return [];
        }

        try {
            $decoded = \Safe\json_decode($json, true);
        } catch (JsonException $jsonException) {
            throw new ChangelogReleaseException(\sprintf('%s is not valid JSON: %s', $path, $jsonException->getMessage()), 0, $jsonException);
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
