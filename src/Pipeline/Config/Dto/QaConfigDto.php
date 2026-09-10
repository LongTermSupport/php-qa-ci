<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Dto;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;

/**
 * The complete, resolved configuration of one pipeline run. Built by
 * QaConfigBuilder from the environment, the project's qaConfig/qa.php and the
 * CLI; immutable from then on.
 *
 * The four run modes are independent of each other. `$ci` is non-interactive:
 * no prompts, no retry loops. `$readOnly` makes the mutating tools dry-run, so
 * a pending change fails the run instead of being applied. `$aggregate` runs
 * every tool and reports the failures together at the end. `$jsonOutput` puts
 * the structured report on stdout and every line of decoration on stderr, and
 * only phpstan honours it.
 *
 * `$singleTool` is the canonical tool name when `-t` was given and
 * `$specifiedPath` the project-relative path when `-p` was; `$quickTests`
 * skips PHPStan, PHPUnit and Infection entirely.
 *
 * @api
 */
final readonly class QaConfigDto
{
    /**
     * @param list<string> $pathsToCheck         absolute paths the path-supporting tools scan
     * @param list<string> $pathsToIgnore        project-relative paths excluded from scans
     * @param list<string> $arkitectExcludePaths src-relative paths excluded from PHPArkitect
     * @param list<string> $twigDirectories      absolute; `templates/` on every platform, because
     *                                           twigCsFixer is gated on Twig rather than on Symfony
     *                                           and skips cleanly when the directory is absent
     * @param list<string> $yamlDirectories      absolute; `config/` on every platform, because
     *                                           yamlLint is gated on symfony/yaml rather than on
     *                                           Symfony and skips cleanly when the directory is absent
     * @param list<string> $shellCheckGlobs      project-relative globs replacing shellCheck's default
     *                                           discovery; empty means discover by extension and shebang
     */
    public function __construct(
        public ProjectPathsDto $paths,
        public PlatformEnum $platform,
        public bool $ci,
        public bool $readOnly,
        public bool $aggregate,
        public bool $jsonOutput,
        public ?string $singleTool,
        public ?string $specifiedPath,
        public array $pathsToCheck,
        public array $pathsToIgnore,
        public string $phpBinPath,
        public string $memoryLimit,
        public bool $xdebugEnabled,
        public bool $quickTests,
        public int $halfCpuThreads,
        public PhpUnitOptionsDto $phpUnit,
        public InfectionOptionsDto $infection,
        public bool $useComposerAudit,
        public TypeCoverageOptionsDto $typeCoverage,
        public bool $useArkitect,
        public array $arkitectExcludePaths,
        public bool $useSensitiveParameterCheck,
        public array $twigDirectories,
        public array $yamlDirectories,
        public array $shellCheckGlobs,
        public DeadCodeOptionsDto $deadCode,
    ) {
    }
}
