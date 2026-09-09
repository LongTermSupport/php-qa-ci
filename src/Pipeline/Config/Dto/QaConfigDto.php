<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;

/**
 * The complete, resolved configuration of one pipeline run. Built by
 * QaConfigBuilder from the environment, the project's qaConfig/qa.php and the
 * CLI; immutable from then on.
 *
 * @api
 */
final readonly class QaConfigDto
{
    /**
     * @param list<string> $pathsToCheck         absolute paths the path-supporting tools scan
     * @param list<string> $pathsToIgnore        project-relative paths excluded from scans
     * @param list<string> $arkitectExcludePaths src-relative paths excluded from PHPArkitect
     * @param list<string> $twigDirectories      absolute, Symfony platform only
     * @param list<string> $yamlDirectories      absolute, Symfony platform only
     */
    public function __construct(
        public ProjectPathsDto $paths,
        public PlatformEnum $platform,
        /** Non-interactive: no prompts, no retry loops. */
        public bool $ci,
        /** Mutating tools run in dry-run and a pending change fails the run. */
        public bool $readOnly,
        /** Every tool runs and failures are reported together at the end. */
        public bool $aggregate,
        /** Structured output on stdout, decoration on stderr (phpstan only). */
        public bool $jsonOutput,
        /** Canonical tool name when -t was given. */
        public ?string $singleTool,
        /** Project-relative path when -p was given. */
        public ?string $specifiedPath,
        public array $pathsToCheck,
        public array $pathsToIgnore,
        public string $phpBinPath,
        public string $memoryLimit,
        public bool $xdebugEnabled,
        /** Skip PHPStan, PHPUnit and Infection entirely. */
        public bool $quickTests,
        public int $halfCpuThreads,
        public PhpUnitOptionsDto $phpUnit,
        public InfectionOptionsDto $infection,
        public bool $useComposerAudit,
        public bool $useArkitect,
        public array $arkitectExcludePaths,
        public bool $useSensitiveParameterCheck,
        public array $twigDirectories,
        public array $yamlDirectories,
    ) {
    }
}
