<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use RuntimeException;

/**
 * The vendors a project counts as first party, for the constraint freshness
 * check. `extra.php-qa-ci.firstPartyVendors` (a list of vendor names) says so
 * outright, an empty list meaning none; when the key is absent the project's
 * own vendor, the part of composer.json `name` before the slash, is the one.
 * A project with neither has no first-party vendors, and says so rather than
 * checking nothing in silence.
 *
 * @internal
 */
final readonly class FirstPartyVendors
{
    /** The composer.json `extra` key php-qa-ci reads its settings from. */
    public const string EXTRA_NAMESPACE = 'php-qa-ci';

    /** The setting inside it; printed in messages as `extra.php-qa-ci.firstPartyVendors`. */
    public const string EXTRA_KEY = 'firstPartyVendors';

    /** The setting as a message names it. */
    private const string SETTING = 'extra.' . self::EXTRA_NAMESPACE . '.' . self::EXTRA_KEY;

    /** Where the vendor comes from when the setting is absent. */
    private const string FROM_NAME = "the vendor of this project's own name";

    /** @param list<string> $vendors lower-case vendor names */
    private function __construct(
        public array $vendors,
        private string $explanation,
    ) {
    }

    /**
     * @param array<array-key, mixed> $manifest the decoded composer.json
     *
     * @throws RuntimeException when the setting is present and is not a list of vendor names
     */
    public static function fromManifest(array $manifest): self
    {
        $extra    = $manifest['extra']                                ?? null;
        $settings = \is_array($extra) ? $extra[self::EXTRA_NAMESPACE] ?? null : null;
        if (\is_array($settings) && \array_key_exists(self::EXTRA_KEY, $settings)) {
            return self::fromSetting($settings[self::EXTRA_KEY]);
        }

        $name   = $manifest['name'] ?? null;
        $vendor = \is_string($name) && str_contains($name, '/') ? strtolower(explode('/', $name, 2)[0]) : '';
        if ('' === $vendor) {
            return new self([], \sprintf('no first-party vendors: composer.json has no "name" and no %s', self::SETTING));
        }

        return new self([$vendor], \sprintf('%s (from %s)', $vendor, self::FROM_NAME));
    }

    public function owns(string $package): bool
    {
        return \in_array(strtolower(explode('/', $package, 2)[0]), $this->vendors, true);
    }

    /** One phrase for the output: who is first party and why, or why nobody is. */
    public function describe(): string
    {
        return $this->explanation;
    }

    /** @throws RuntimeException when $setting is not a list of vendor names */
    private static function fromSetting(mixed $setting): self
    {
        $malformed = new RuntimeException(\sprintf('%s must be a list of vendor names (the part of a package name before the slash), for example ["acme"]', self::SETTING));
        if (!\is_array($setting) || !array_is_list($setting)) {
            throw $malformed;
        }

        $vendors = [];
        foreach ($setting as $vendor) {
            if (!\is_string($vendor) || '' === trim($vendor) || str_contains($vendor, '/')) {
                throw $malformed;
            }

            $vendors[] = strtolower(trim($vendor));
        }

        if ([] === $vendors) {
            return new self([], \sprintf('no first-party vendors: %s is empty', self::SETTING));
        }

        return new self($vendors, \sprintf('%s (from %s)', implode(', ', $vendors), self::SETTING));
    }
}
