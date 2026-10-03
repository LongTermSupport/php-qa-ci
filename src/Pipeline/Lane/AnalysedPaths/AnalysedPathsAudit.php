<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\AnalysedPaths;

use LTS\PHPQA\Pipeline\Lane\AnalysedPaths\Dto\AnalysedPathsVerdictDto;

/**
 * Accounts for every PHP file in a project: it is under an analysed path (the
 * checked paths every scanning lane reads), under an ignored path, under a path
 * the project declared unanalysed with a reason, or under a default exclusion.
 * Whatever is under none of them is PHP no rule has seen, and is reported by
 * directory, since a directory is what a project analyses or declares.
 *
 * Paths are project-relative and compared by whole segment, so `config` covers
 * `config/packages/a.php` but not `configuration/a.php`. An analysed path of
 * '' is the whole project.
 *
 * @internal
 */
final readonly class AnalysedPathsAudit
{
    /**
     * Directories no project analyses, and why. Consulted last, so a project
     * that does analyse one simply adds it to its checked paths.
     *
     * @var array<string, string>
     */
    public const array DEFAULT_UNANALYSED = [
        'vendor' => 'third-party code Composer installs',
        'var'    => 'runtime output: caches, logs and the QA artefacts',
    ];

    /** Paths are compared as git prints them, whatever the host's separator. */
    private const string SEPARATOR = '/';

    /** What dirname() returns for a file at the project root. */
    private const string CURRENT_DIRECTORY = '.';

    /** @var list<string> */
    private array $analysedPaths;

    /** @var list<string> */
    private array $ignoredPaths;

    /** @var list<string> */
    private array $declaredPaths;

    /**
     * @param list<string>          $analysedPaths project-relative; '' is the whole project
     * @param list<string>          $ignoredPaths  project-relative
     * @param array<string, string> $declared      project-relative path => the reason it is not analysed
     */
    public function __construct(array $analysedPaths, array $ignoredPaths, array $declared)
    {
        $this->analysedPaths = array_map($this->normalise(...), $analysedPaths);
        $this->ignoredPaths  = array_map($this->normalise(...), $ignoredPaths);
        $this->declaredPaths = array_map($this->normalise(...), array_keys($declared));
    }

    /** @param string ...$phpFiles project-relative */
    public function audit(string ...$phpFiles): AnalysedPathsVerdictDto
    {
        $unclassified = [];
        $inUse        = [];
        foreach ($phpFiles as $phpFile) {
            $file = $this->normalise($phpFile);
            if ($this->isAnalysedOrIgnored($file)) {
                continue;
            }

            $declaredBy = $this->containing($file, ...$this->declaredPaths);
            if (null !== $declaredBy) {
                $inUse[$declaredBy] = ($inUse[$declaredBy] ?? 0) + 1;

                continue;
            }

            if (null !== $this->containing($file, ...array_keys(self::DEFAULT_UNANALYSED))) {
                continue;
            }

            $unit                = $this->unit($file);
            $unclassified[$unit] = ($unclassified[$unit] ?? 0) + 1;
        }

        ksort($unclassified, SORT_STRING);
        ksort($inUse, SORT_STRING);

        $contradicted = $this->contradicted();

        return new AnalysedPathsVerdictDto(
            phpFiles: \count($phpFiles),
            unclassified: $unclassified,
            declaredInUse: $inUse,
            stale: array_values(array_filter(
                $this->declaredPaths,
                static fn (string $declared): bool => !isset($inUse[$declared]) && !isset($contradicted[$declared]),
            )),
            contradicted: $contradicted,
        );
    }

    private function isAnalysedOrIgnored(string $file): bool
    {
        return null !== $this->containing($file, ...$this->analysedPaths)
            || null !== $this->containing($file, ...$this->ignoredPaths);
    }

    /**
     * Declarations inside an analysed path: the PHP there is analysed, so the
     * declaration states something false.
     *
     * @return array<string, string> declared path => the analysed path containing it
     */
    private function contradicted(): array
    {
        $contradicted = [];
        foreach ($this->declaredPaths as $declared) {
            $analysedBy = $this->containing($declared, ...$this->analysedPaths);
            if (null !== $analysedBy) {
                $contradicted[$declared] = $analysedBy;
            }
        }

        return $contradicted;
    }

    /** The first of $paths that is $path or one of its ancestors, or null. */
    private function containing(string $path, string ...$paths): ?string
    {
        foreach ($paths as $candidate) {
            if ('' === $candidate || $path === $candidate || str_starts_with($path, $candidate . self::SEPARATOR)) {
                return $candidate;
            }
        }

        return null;
    }

    /** A file's directory with a trailing slash, or the file itself at the project root. */
    private function unit(string $file): string
    {
        $directory = \dirname($file);

        return self::CURRENT_DIRECTORY === $directory ? $file : $directory . self::SEPARATOR;
    }

    private function normalise(string $path): string
    {
        $normalised = trim($path, self::SEPARATOR);
        while (str_starts_with($normalised, self::CURRENT_DIRECTORY . self::SEPARATOR)) {
            $normalised = ltrim(substr($normalised, 2), self::SEPARATOR);
        }

        return self::CURRENT_DIRECTORY === $normalised ? '' : $normalised;
    }
}
