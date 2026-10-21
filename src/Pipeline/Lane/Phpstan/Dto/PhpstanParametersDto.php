<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan\Dto;

/**
 * What `phpstan.phar dump-parameters --json` says PHPStan merged from a config: the `tmpDir` when
 * it is the project's own choice, and the `paths`, `excludePaths` and `fileExtensions` that decide
 * which files a run analyses. Every path is absolute, as PHPStan resolves it.
 *
 * @internal
 */
final readonly class PhpstanParametersDto
{
    /** PHPStan's default `fileExtensions`. */
    private const string PHP = 'php';

    /** PHPStan's default tmpDir, under its `sysGetTempDir`. */
    private const string DEFAULT_TMP_DIR = '/phpstan';

    /** The two lists PHPStan keeps `excludePaths` in; a file in either is not analysed. */
    private const array EXCLUDE_LISTS = ['analyseAndScan', 'analyse'];

    /**
     * @param ?string      $tmpDir         the project's tmpDir; null when it is PHPStan's default
     * @param list<string> $paths          analysed files and directories
     * @param list<string> $excludePaths   files, directories and fnmatch() patterns
     * @param list<string> $fileExtensions the suffixes read from an analysed directory
     */
    public function __construct(
        public ?string $tmpDir,
        public array $paths,
        public array $excludePaths,
        public array $fileExtensions,
    ) {
    }

    /** PHPStan's own defaults: no paths, nothing excluded, `.php` files. */
    public static function defaults(): self
    {
        return new self(null, [], [], [self::PHP]);
    }

    /** The parameters in a dump's JSON; null when the output is no JSON object. */
    public static function fromDump(string $json): ?self
    {
        if (!json_validate($json)) {
            return null;
        }

        $dump = \Safe\json_decode($json, true);
        if (!\is_array($dump)) {
            return null;
        }

        $tmpDir     = $dump['tmpDir']        ?? null;
        $systemTemp = $dump['sysGetTempDir'] ?? null;
        $isProjects = \is_string($tmpDir) && \is_string($systemTemp) && $systemTemp . self::DEFAULT_TMP_DIR !== $tmpDir;
        $excludes   = $dump['excludePaths'] ?? [];
        $listed     = \is_array($excludes) && array_is_list($excludes)
            ? $excludes
            : array_merge(...array_map(static fn (string $list): array => \is_array($excludes) && \is_array($excludes[$list] ?? null) ? $excludes[$list] : [], self::EXCLUDE_LISTS));
        $extensions = self::strings($dump['fileExtensions'] ?? null);

        return new self(
            $isProjects ? $tmpDir : null,
            self::strings($dump['paths'] ?? null),
            self::strings($listed),
            [] === $extensions ? [self::PHP] : $extensions,
        );
    }

    /** @return list<string> the strings in $value when it is an array, in order */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }
}
