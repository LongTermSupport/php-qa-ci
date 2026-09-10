<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;

/**
 * Creates var/qa and var/qa/cache (each self-excluding via .gitignore) and
 * ensures the project's root .gitignore carries the managed block of QA
 * runtime-cache excludes, so a misconfigured tool can never commit a cache.
 *
 * @internal
 */
final readonly class DirectoryPreparer
{
    public const string MARKER_START = '# BEGIN php-qa-ci managed QA runtime excludes';

    private const string MANAGED_BLOCK = <<<'GITIGNORE'

        # BEGIN php-qa-ci managed QA runtime excludes — do not edit
        # These patterns ensure QA tool runtime caches are never committed,
        # regardless of where they happen to appear in the project tree.
        # Managed by php-qa-ci (LTS\PHPQA\Pipeline\Runner\DirectoryPreparer)
        **/.phpunit.cache/
        **/.qa-lock/
        **/.php-cs-fixer.cache
        **/.php_cs.cache
        **/.rector.cache/
        **/phpstan-result.json
        # END php-qa-ci managed QA runtime excludes

        GITIGNORE;

    public function prepare(ProjectPathsDto $paths): void
    {
        foreach ([$paths->varDir, $paths->cacheDir] as $dir) {
            if (!is_dir($dir)) {
                \Safe\mkdir($dir, 0o777, true);
            }

            \Safe\file_put_contents($dir . '/.gitignore', "\n*\n!.gitignore\n");
        }

        $this->ensureRootGitignoreGuards($paths->projectRoot . '/.gitignore');
    }

    private function ensureRootGitignoreGuards(string $gitignore): void
    {
        $existing = is_file($gitignore) ? \Safe\file_get_contents($gitignore) : '';
        if (str_contains($existing, self::MARKER_START)) {
            return;
        }

        $prefix = '' !== $existing && !str_ends_with($existing, "\n") ? "\n" : '';
        \Safe\file_put_contents($gitignore, $existing . $prefix . self::MANAGED_BLOCK);
    }
}
