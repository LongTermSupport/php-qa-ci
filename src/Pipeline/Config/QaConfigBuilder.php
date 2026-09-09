<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto;

/**
 * Immutable builder for a run's configuration. The pipeline seeds it from the
 * environment and the CLI; a project's qaConfig/qa.php then receives it and
 * returns an adjusted copy:
 *
 *   return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
 *       ->withInfectionFloors(msi: 82, coveredMsi: 82)
 *       ->withIgnoredPaths('tests/assets');
 *
 * build() applies the derivations that depend on adjustable inputs (coverage
 * needs Xdebug, Infection needs coverage) so an override cannot switch on
 * something the host cannot run.
 *
 * @api
 */
final readonly class QaConfigBuilder
{
    /**
     * @param list<string> $pathsToCheck
     * @param list<string> $pathsToIgnore
     * @param list<string> $arkitectExcludePaths
     * @param list<string> $twigDirectories
     * @param list<string> $yamlDirectories
     */
    public function __construct(
        private ProjectPathsDto $paths,
        private PlatformEnum $platform,
        private bool $ci,
        private bool $readOnly,
        private bool $aggregate,
        private bool $jsonOutput,
        private ?string $singleTool,
        private ?string $specifiedPath,
        private array $pathsToCheck,
        private array $pathsToIgnore,
        private string $phpBinPath,
        private string $memoryLimit,
        private bool $xdebugEnabled,
        private bool $quickTests,
        private int $halfCpuThreads,
        private bool $phpUnitCoverage,
        private bool $phpUnitQuickTests,
        private bool $phpUnitIterativeMode,
        private bool $useInfection,
        private int $infectionThreads,
        private bool $infectionOnlyCovered,
        private int $minMsi,
        private int $minCoveredMsi,
        private ?string $infectionDiffBase,
        private int $infectionDiffCoveredMsi,
        private bool $useComposerAudit,
        private TypeCoverageOptionsDto $typeCoverage,
        private bool $useArkitect,
        private array $arkitectExcludePaths,
        private bool $useSensitiveParameterCheck,
        private array $twigDirectories,
        private array $yamlDirectories,
    ) {
    }

    /**
     * The pipeline's defaults for a project, before any qaConfig/qa.php
     * adjustment. Every value the Bash defaults set is set here.
     */
    public static function defaults(
        ProjectPathsDto $paths,
        PlatformEnum $platform,
        EnvironmentReader $env,
        string $phpBinPath,
        bool $xdebugEnabled,
        int $halfCpuThreads,
        bool $ci,
        bool $readOnly,
        bool $aggregate,
        bool $jsonOutput,
        ?string $singleTool,
        ?string $specifiedPath,
    ): self {
        $pathsToCheck = null === $specifiedPath
            ? [$paths->testsDir, $paths->srcDir]
            : [$paths->projectRoot . '/' . $specifiedPath];

        $halfCpu = max(1, $halfCpuThreads);

        return new self(
            paths: $paths,
            platform: $platform,
            ci: $ci,
            readOnly: $readOnly,
            aggregate: $aggregate,
            jsonOutput: $jsonOutput,
            singleTool: $singleTool,
            specifiedPath: $specifiedPath,
            pathsToCheck: $pathsToCheck,
            pathsToIgnore: [],
            phpBinPath: $phpBinPath,
            memoryLimit: $env->string('phpqaMemoryLimit') ?? '4G',
            xdebugEnabled: $xdebugEnabled,
            quickTests: $env->bool('phpqaQuickTests', false),
            halfCpuThreads: $halfCpu,
            phpUnitCoverage: $env->bool('phpUnitCoverage', true),
            phpUnitQuickTests: $env->bool('phpUnitQuickTests', false),
            phpUnitIterativeMode: $env->bool('phpUnitIterativeMode', false),
            useInfection: $env->bool('useInfection', true),
            infectionThreads: $env->int('infectionThreads', $halfCpu),
            infectionOnlyCovered: $env->bool('infectionOnlyCovered', false),
            minMsi: $env->int('mutationScoreIndicator', 60),
            minCoveredMsi: $env->int('coveredCodeMSI', 80),
            infectionDiffBase: $env->string('infectionDiffBase'),
            infectionDiffCoveredMsi: $env->int('infectionDiffCoveredMsi', 100),
            useComposerAudit: $env->bool('useComposerAudit', true),
            typeCoverage: new TypeCoverageOptionsDto(),
            useArkitect: $env->bool('useArkitect', true),
            arkitectExcludePaths: [],
            useSensitiveParameterCheck: $env->bool('useSensitiveParameterCheck', true),
            twigDirectories: [$paths->projectRoot . '/templates'],
            yamlDirectories: [$paths->projectRoot . '/config'],
        );
    }

    public function withMemoryLimit(string $memoryLimit): self
    {
        return $this->with(memoryLimit: $memoryLimit);
    }

    /** Project-relative paths the scanning tools skip (e.g. 'tests/assets'). */
    public function withIgnoredPaths(string ...$paths): self
    {
        return $this->with(pathsToIgnore: [...$this->pathsToIgnore, ...array_values($paths)]);
    }

    /** Additional project-relative paths the scanning tools cover. */
    public function withCheckedPaths(string ...$paths): self
    {
        $absolute = array_map(fn (string $path): string => $this->paths->projectRoot . '/' . ltrim($path, '/'), array_values($paths));

        return $this->with(pathsToCheck: [...$this->pathsToCheck, ...$absolute]);
    }

    public function withPhpUnitCoverage(bool $coverage): self
    {
        return $this->with(phpUnitCoverage: $coverage);
    }

    /** Order by defects, stop at the first failure, no coverage (the `uniterate` pseudo-tool). */
    public function withPhpUnitIterativeMode(bool $iterative): self
    {
        return $this->with(phpUnitIterativeMode: $iterative);
    }

    public function withInfection(bool $enabled): self
    {
        return $this->with(useInfection: $enabled);
    }

    public function withInfectionFloors(int $msi, int $coveredMsi): self
    {
        return $this->with(minMsi: $msi, minCoveredMsi: $coveredMsi);
    }

    public function withInfectionThreads(int $threads): self
    {
        return $this->with(infectionThreads: max(1, $threads));
    }

    public function withInfectionDiffBase(?string $gitRef, int $coveredMsi = 100): self
    {
        return $this->with(infectionDiffBase: $gitRef, infectionDiffCoveredMsi: $coveredMsi);
    }

    public function withComposerAudit(bool $enabled): self
    {
        return $this->with(useComposerAudit: $enabled);
    }

    /**
     * Minimum percentage of declarations carrying a native type, per kind.
     * Every floor is off unless given, so the check does nothing until a
     * project opts in and raises each ratchet as it earns it.
     */
    public function withTypeCoverageFloors(
        ?int $returnType = null,
        ?int $paramType = null,
        ?int $propertyType = null,
        ?int $constantType = null,
        ?int $declare = null,
    ): self {
        return $this->with(typeCoverage: new TypeCoverageOptionsDto($returnType, $paramType, $propertyType, $constantType, $declare));
    }

    public function withArkitect(bool $enabled): self
    {
        return $this->with(useArkitect: $enabled);
    }

    /** src-relative paths PHPArkitect must not check (generated code). */
    public function withArkitectExcludedPaths(string ...$paths): self
    {
        return $this->with(arkitectExcludePaths: [...$this->arkitectExcludePaths, ...array_values($paths)]);
    }

    public function withSensitiveParameterCheck(bool $enabled): self
    {
        return $this->with(useSensitiveParameterCheck: $enabled);
    }

    /** Absolute or project-relative directories for Symfony's twig linter. */
    public function withTwigDirectories(string ...$directories): self
    {
        return $this->with(twigDirectories: $this->absolute(...$directories));
    }

    /** Absolute or project-relative directories for Symfony's yaml linter. */
    public function withYamlDirectories(string ...$directories): self
    {
        return $this->with(yamlDirectories: $this->absolute(...$directories));
    }

    public function build(): QaConfigDto
    {
        $coverage  = $this->phpUnitCoverage && $this->xdebugEnabled;
        $infection = $this->useInfection    && $coverage;

        return new QaConfigDto(
            paths: $this->paths,
            platform: $this->platform,
            ci: $this->ci,
            readOnly: $this->readOnly,
            aggregate: $this->aggregate,
            jsonOutput: $this->jsonOutput,
            singleTool: $this->singleTool,
            specifiedPath: $this->specifiedPath,
            pathsToCheck: $this->pathsToCheck,
            pathsToIgnore: $this->pathsToIgnore,
            phpBinPath: $this->phpBinPath,
            memoryLimit: $this->memoryLimit,
            xdebugEnabled: $this->xdebugEnabled,
            quickTests: $this->quickTests,
            halfCpuThreads: $this->halfCpuThreads,
            phpUnit: new PhpUnitOptionsDto(
                coverage: $coverage,
                quickTests: $this->phpUnitQuickTests,
                iterativeMode: $this->phpUnitIterativeMode,
            ),
            infection: new InfectionOptionsDto(
                enabled: $infection,
                threads: $this->infectionThreads,
                onlyCovered: $this->infectionOnlyCovered,
                minMsi: $this->minMsi,
                minCoveredMsi: $this->minCoveredMsi,
                diffBase: $this->infectionDiffBase,
                diffCoveredMsi: $this->infectionDiffCoveredMsi,
            ),
            useComposerAudit: $this->useComposerAudit,
            typeCoverage: $this->typeCoverage,
            useArkitect: $this->useArkitect,
            arkitectExcludePaths: $this->arkitectExcludePaths,
            useSensitiveParameterCheck: $this->useSensitiveParameterCheck,
            twigDirectories: $this->twigDirectories,
            yamlDirectories: $this->yamlDirectories,
        );
    }

    /** @return list<string> */
    private function absolute(string ...$directories): array
    {
        return array_map(
            fn (string $directory): string => str_starts_with($directory, '/')
                ? $directory
                : $this->paths->projectRoot . '/' . $directory,
            array_values($directories),
        );
    }

    /**
     * @param list<string>|null $pathsToCheck
     * @param list<string>|null $pathsToIgnore
     * @param list<string>|null $arkitectExcludePaths
     * @param list<string>|null $twigDirectories
     * @param list<string>|null $yamlDirectories
     */
    private function with(
        ?array $pathsToCheck = null,
        ?array $pathsToIgnore = null,
        ?string $memoryLimit = null,
        ?bool $phpUnitCoverage = null,
        ?bool $phpUnitIterativeMode = null,
        ?bool $useInfection = null,
        ?int $infectionThreads = null,
        ?int $minMsi = null,
        ?int $minCoveredMsi = null,
        ?string $infectionDiffBase = null,
        ?int $infectionDiffCoveredMsi = null,
        ?bool $useComposerAudit = null,
        ?TypeCoverageOptionsDto $typeCoverage = null,
        ?bool $useArkitect = null,
        ?array $arkitectExcludePaths = null,
        ?bool $useSensitiveParameterCheck = null,
        ?array $twigDirectories = null,
        ?array $yamlDirectories = null,
    ): self {
        return new self(
            paths: $this->paths,
            platform: $this->platform,
            ci: $this->ci,
            readOnly: $this->readOnly,
            aggregate: $this->aggregate,
            jsonOutput: $this->jsonOutput,
            singleTool: $this->singleTool,
            specifiedPath: $this->specifiedPath,
            pathsToCheck: $pathsToCheck   ?? $this->pathsToCheck,
            pathsToIgnore: $pathsToIgnore ?? $this->pathsToIgnore,
            phpBinPath: $this->phpBinPath,
            memoryLimit: $memoryLimit ?? $this->memoryLimit,
            xdebugEnabled: $this->xdebugEnabled,
            quickTests: $this->quickTests,
            halfCpuThreads: $this->halfCpuThreads,
            phpUnitCoverage: $phpUnitCoverage ?? $this->phpUnitCoverage,
            phpUnitQuickTests: $this->phpUnitQuickTests,
            phpUnitIterativeMode: $phpUnitIterativeMode ?? $this->phpUnitIterativeMode,
            useInfection: $useInfection                 ?? $this->useInfection,
            infectionThreads: $infectionThreads         ?? $this->infectionThreads,
            infectionOnlyCovered: $this->infectionOnlyCovered,
            minMsi: $minMsi                                         ?? $this->minMsi,
            minCoveredMsi: $minCoveredMsi                           ?? $this->minCoveredMsi,
            infectionDiffBase: $infectionDiffBase                   ?? $this->infectionDiffBase,
            infectionDiffCoveredMsi: $infectionDiffCoveredMsi       ?? $this->infectionDiffCoveredMsi,
            useComposerAudit: $useComposerAudit                     ?? $this->useComposerAudit,
            typeCoverage: $typeCoverage                             ?? $this->typeCoverage,
            useArkitect: $useArkitect                               ?? $this->useArkitect,
            arkitectExcludePaths: $arkitectExcludePaths             ?? $this->arkitectExcludePaths,
            useSensitiveParameterCheck: $useSensitiveParameterCheck ?? $this->useSensitiveParameterCheck,
            twigDirectories: $twigDirectories                       ?? $this->twigDirectories,
            yamlDirectories: $yamlDirectories                       ?? $this->yamlDirectories,
        );
    }
}
