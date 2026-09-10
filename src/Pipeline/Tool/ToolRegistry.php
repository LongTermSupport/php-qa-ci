<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownPhaseException;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException;

/**
 * The tool set a run reads: phases in execution order, and for every tool its
 * name, `-t` aliases, phase membership, path support, gate, banner and
 * description. The CLI derives its usage text and its path-support gate from
 * here; the runner derives the phase sequence and each phase's ordered tools.
 *
 * `shipped()` is the single source of truth for what php-qa-ci ships;
 * PipelineBuilder assembles a registry from it (or from nothing). Each phase
 * contributes its own `-t` runner, so the runners are never listed here.
 *
 * The shipped tokens, their canonical targets, the path-support
 * classification and the phase ordering are frozen by
 * tests/Small/Pipeline/ToolRegistryCharacterisationTest.php. Changing any of
 * them is a deliberate behavioural change, not a refactor.
 *
 * @internal
 */
final readonly class ToolRegistry
{
    private const string PHPSTAN = 'phpstan';

    private const string PHPUNIT = 'phpunit';

    private const string INFECTION = 'infection';

    /** @var array<string, PhaseDto> keyed by name, in execution order */
    private array $phases;

    /** @var array<string, ToolDefinitionDto> keyed by canonical name: the phase runners first, then the tools in the given order */
    private array $definitions;

    /** @var array<string, string> token => canonical name */
    private array $byToken;

    /**
     * @param list<PhaseDto>          $phases in execution order
     * @param list<ToolDefinitionDto> $tools  the leaf and pseudo tools; a phase named by a tool must be one of $phases
     */
    public function __construct(array $phases, array $tools)
    {
        $byPhase = [];
        $byName  = [];
        $byToken = [];
        foreach ($phases as $phase) {
            $byPhase[$phase->name] = $phase;
            $runner                = $phase->runner();
            $byName[$runner->name] = $runner;
        }

        foreach ($tools as $tool) {
            if (null !== $tool->phase && !isset($byPhase[$tool->phase])) {
                throw UnknownPhaseException::forName($tool->phase, ...array_keys($byPhase));
            }

            $byName[$tool->name] = $tool;
        }

        foreach ($byName as $definition) {
            foreach ($definition->aliases as $alias) {
                $byToken[$alias] = $definition->name;
            }
        }

        $this->phases      = $byPhase;
        $this->definitions = $byName;
        $this->byToken     = $byToken;
    }

    public static function shipped(): self
    {
        $codingStandards = PhaseEnum::CodingStandards->value;
        $linting         = PhaseEnum::Linting->value;
        $staticAnalysis  = PhaseEnum::StaticAnalysis->value;
        $testing         = PhaseEnum::Testing->value;

        return new self(
            array_map(static fn (PhaseEnum $phase): PhaseDto => $phase->phase(), PhaseEnum::cases()),
            [
                new ToolDefinitionDto('rector', ['r', 'rector'], 'Rector', $codingStandards, true, banner: 'Running Rector'),
                new ToolDefinitionDto('phpCsFixer', ['f', 'fixer', 'csfixer'], 'PHP-CS-Fixer', $codingStandards, true, banner: 'Running PHP-CS-Fixer'),
                new ToolDefinitionDto('twigCsFixer', ['twigcs'], 'Twig coding standards (when twig/twig is installed)', $codingStandards, false, banner: 'Running Twig CS Fixer'),
                new ToolDefinitionDto('psr4Validate', ['psr', 'psr4'], 'psr4 validation', $linting, false, banner: 'Validating PSR-4 Roots'),
                new ToolDefinitionDto('composerChecks', ['com', 'composer'], 'composer validation', $linting, false, banner: 'Checking for Composer Issues'),
                new ToolDefinitionDto('packageType', ['pt', 'packagetype', 'packageType'], 'assert composer.json declares an explicit package type (library/project/...)', $linting, false, banner: 'Checking Package Type Is Declared'),
                new ToolDefinitionDto('configTemplateIgnoreList', ['cti', 'configTemplateIgnoreList'], 'audit configDefaults/generic templates against psr4-validate-ignore-list.txt', $linting, false, banner: 'Auditing Config Template Ignore-List Coverage'),
                new ToolDefinitionDto('infectionConfigSourceDirs', ['icsd', 'infectionConfigSourceDirs'], "assert infection.json's source.directories resolve to real directories", $linting, false, banner: 'Checking Infection Config Source Directories Exist'),
                new ToolDefinitionDto('versionPins', ['vp', 'versionPins'], 'assert phpunit.xml, safe scan-files and GitHub Actions PHP pins match the installed PHPUnit / running PHP', $linting, false, banner: 'Checking Version Pins Match The Toolchain In Use'),
                new ToolDefinitionDto('phpStrictTypes', ['st', 'stricttypes'], 'strict types validation', $linting, true, banner: "Setting Strict Types If It's Missing"),
                new ToolDefinitionDto('phpLint', ['lint', 'phplint'], 'phplint', $linting, true, banner: 'Running PHP Lint'),
                new ToolDefinitionDto('opcache', ['oc', 'opcache'], 'assert the code compiles cleanly through OPcache (known OPcache defects that produce crashing or wrong bytecode)', $linting, true, banner: 'Checking The Code Compiles Cleanly Through OPcache'),
                new ToolDefinitionDto('composerRequireChecker', ['cr'], 'composer require checker', $linting, false, banner: 'Running Composer Require Checker'),
                new ToolDefinitionDto('composerDependencyAnalyser', ['cda'], 'unused, shadow and misplaced dependencies', $linting, false, banner: 'Running Composer Dependency Analyser'),
                new ToolDefinitionDto('markdownLinks', ['ml', 'markdown'], 'markdown validation', $linting, false, banner: 'Running Markdown Links Checker'),
                new ToolDefinitionDto('yamlLint', ['yaml'], 'YAML syntax (when symfony/yaml is installed)', $linting, false, banner: 'Running Yaml Linter'),
                new ToolDefinitionDto('shellCheck', ['sc', 'shellcheck', 'shellCheck'], 'ShellCheck over every git-tracked shell script, from the pinned binary php-qa-ci ships', $linting, true, banner: 'Running ShellCheck'),
                new ToolDefinitionDto('branchNamePolicy', ['bnp', 'branchNamePolicy'], 'Branch naming policy (PR convention)', $staticAnalysis, false, banner: 'Checking Branch Name Policy'),
                new ToolDefinitionDto('phpstanIgnoreJustification', ['pij', 'phpstanIgnoreJustification'], 'assert every ignoreErrors entry in qaConfig/phpstan.neon carries a usable justification', $staticAnalysis, false, banner: 'Checking PHPStan ignoreErrors Justifications'),
                new ToolDefinitionDto(self::PHPSTAN, ['stan', self::PHPSTAN], self::PHPSTAN, $staticAnalysis, true, ToolGateEnum::NotQuick, 'Running PHPStan', supportsJson: true),
                new ToolDefinitionDto('deadCode', ['dcd', 'deadcode', 'deadCode'], 'dead-code detection through phpstan.phar (opt-in: withDeadCodeDetection(true) in qaConfig/qa.php)', $staticAnalysis, false, ToolGateEnum::NotQuick, 'Running Dead Code Detection'),
                new ToolDefinitionDto('phpArkitect', ['arch', 'arkitect', 'phparkitect'], 'PHPArkitect architecture rules (on by default; useArkitect=0 to disable)', $staticAnalysis, false, banner: 'Running PHPArkitect (architecture rules)'),
                new ToolDefinitionDto('sensitiveParameterUsage', ['spu', 'sensitiveparameter', 'sensitiveParameterUsage'], 'assert #[\SensitiveParameter] is used somewhere in src/', $staticAnalysis, false, banner: 'Checking SensitiveParameter Usage'),
                new ToolDefinitionDto(self::PHPUNIT, ['unit', self::PHPUNIT], self::PHPUNIT, $testing, true, ToolGateEnum::NotQuick, 'Running PHPUnit Tests'),
                new ToolDefinitionDto(self::INFECTION, ['infect', self::INFECTION], self::INFECTION, $testing, false, ToolGateEnum::Infection, 'Running Infection (mutation testing)'),
                new ToolDefinitionDto('phpcpd', ['cpd', 'phpcpd'], 'copy/paste detection, informational', null, true),
                new ToolDefinitionDto('uniterate', ['uniterate'], 'phpunit iterative mode - prioritise broken tests and fail on error', null, false, target: self::PHPUNIT),
            ],
        );
    }

    /** @return list<PhaseDto> in execution order */
    public function phases(): array
    {
        return array_values($this->phases);
    }

    /**
     * The lanes a platform adds to a phase, on top of the generic set. They
     * are not `-t` selectable and are not part of the frozen registry.
     *
     * The one remaining entry shells out to `bin/console lint:twig`, which needs
     * the application's own Twig environment, so it is coupled to the Symfony
     * console rather than to the file format it checks. A lane that only needs
     * a library is NOT a platform lane: twigCsFixer runs a standalone PHAR and
     * yamlLint the script symfony/yaml ships, so both live in the shipped
     * registry and decide for themselves.
     *
     * @return list<ToolDefinitionDto>
     */
    public static function platformLanes(PlatformEnum $platform, string $phase): array
    {
        if (PlatformEnum::Symfony !== $platform || PhaseEnum::Linting->value !== $phase) {
            return [];
        }

        return [
            new ToolDefinitionDto('twigLint', [], 'Symfony twig linter', $phase, false, banner: 'Running Twig Linter'),
        ];
    }

    /** Resolve a `-t` token to its definition. */
    public function resolve(string $token): ToolDefinitionDto
    {
        $name = $this->byToken[$token] ?? null;
        if (null === $name) {
            throw UnknownToolException::forToken($token);
        }

        return $this->definitions[$name];
    }

    /** Look up by canonical name. */
    public function definition(string $name): ToolDefinitionDto
    {
        return $this->definitions[$name] ?? throw UnknownToolException::forToken($name);
    }

    /** @return list<ToolDefinitionDto> in registry order */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<ToolDefinitionDto> the leaf tools of a phase, in registry order */
    public function toolsForPhase(string $phase): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (ToolDefinitionDto $tool): bool => $tool->phase === $phase && !$tool->isPhaseRunner && null === $tool->target,
        ));
    }

    public function supportsPaths(string $token): bool
    {
        $name = $this->byToken[$token]    ?? $token;
        $tool = $this->definitions[$name] ?? null;

        return $tool instanceof ToolDefinitionDto && $tool->supportsPaths;
    }

    /** @return list<string> every token (name and aliases) of every path-supporting tool */
    public function pathSupportingTokens(): array
    {
        $tokens = [];
        foreach ($this->definitions as $tool) {
            if ($tool->supportsPaths) {
                foreach ($tool->tokens() as $token) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /** @return list<string> one "%-26s %s" line per tool for the usage text */
    public function usageLines(): array
    {
        return array_map(
            static fn (ToolDefinitionDto $tool): string => \sprintf('%-26s %s', $tool->display(), $tool->description),
            $this->all(),
        );
    }
}
