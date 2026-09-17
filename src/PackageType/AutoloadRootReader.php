<?php

declare(strict_types=1);

namespace LTS\PHPQA\PackageType;

use LTS\PHPQA\Helper;

/**
 * Resolves the analysed project's PSR-4 autoload roots from its `composer.json`,
 * split by the only distinction that matters to a shipping decision: `autoload`
 * is installed into every consumer and every production deployment, `autoload-dev`
 * is not (`composer install --no-dev` drops it).
 *
 * A root is the pair the rules need — the namespace prefix as written, and the
 * directories it maps to as written — so a rule can decide "is this class shipped?"
 * by namespace, by path, or by both, without hard-coding `src/`. A project whose
 * production root is `lib/` or `app/` is read exactly as correctly.
 *
 * Directories are returned verbatim (relative to the project root, trailing slash
 * as the author wrote it). Resolving them against a project root is the caller's
 * job, because only the caller knows which root its analyser is anchored to.
 *
 * The optional constructor override makes the reader injectable so unit tests (and
 * the rules' own tests) can drive any layout without touching a real `composer.json`.
 *
 * @see DevAutoloadNamespaceReader for the prefix-only view of the dev section
 */
final readonly class AutoloadRootReader
{
    private const string PRODUCTION_SECTION = 'autoload';

    private const string DEV_SECTION = 'autoload-dev';

    private const string PSR4_KEY = 'psr-4';

    /**
     * @param array<int|string, mixed>|null $composerJsonOverride decoded composer.json
     *                                                            for tests; null reads the live project file
     */
    public function __construct(private ?array $composerJsonOverride = null)
    {
    }

    /**
     * PSR-4 roots that ARE shipped: installed for every consumer, present in production.
     *
     * @return array<string, list<string>> namespace prefix (as written) => directories (as written)
     */
    public function productionRoots(): array
    {
        return $this->roots(self::PRODUCTION_SECTION);
    }

    /**
     * PSR-4 roots that are NOT shipped: dropped by `composer install --no-dev`.
     *
     * @return array<string, list<string>> namespace prefix (as written) => directories (as written)
     */
    public function devRoots(): array
    {
        return $this->roots(self::DEV_SECTION);
    }

    /**
     * @return array<string, list<string>>
     */
    private function roots(string $section): array
    {
        $composerJson = $this->composerJsonOverride ?? Helper::getComposerJsonDecoded();

        $sectionValue = $composerJson[$section] ?? null;
        if (!\is_array($sectionValue)) {
            return [];
        }

        $psr4 = $sectionValue[self::PSR4_KEY] ?? null;
        if (!\is_array($psr4)) {
            return [];
        }

        $roots = [];
        foreach ($psr4 as $prefix => $directories) {
            if (!\is_string($prefix) || '' === $prefix) {
                continue;
            }

            $resolved = $this->directories($directories);
            if ([] === $resolved) {
                continue;
            }

            $roots[$prefix] = $resolved;
        }

        return $roots;
    }

    /**
     * Composer accepts either a single directory string or a list of them for one prefix.
     *
     * @return list<string>
     */
    private function directories(mixed $directories): array
    {
        if (\is_string($directories)) {
            return '' === $directories ? [] : [$directories];
        }

        if (!\is_array($directories)) {
            return [];
        }

        $resolved = [];
        foreach ($directories as $directory) {
            if (\is_string($directory) && '' !== $directory) {
                $resolved[] = $directory;
            }
        }

        return $resolved;
    }
}
