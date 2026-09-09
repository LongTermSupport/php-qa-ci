<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException;

/**
 * The single source of truth for the tool set: names, `-t` aliases, phase
 * membership and order, path support, gates, banners and descriptions. The
 * CLI derives its usage text and its path-support gate from here; the runner
 * derives each phase's ordered tool sequence.
 *
 * The accepted tokens, their canonical targets, the path-support
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

    /** @var array<string, ToolDefinitionDto> keyed by canonical name, in registry order */
    private array $definitions;

    /** @var array<string, string> token => canonical name */
    private array $byToken;

    public function __construct(ToolDefinitionDto ...$definitions)
    {
        $byName  = [];
        $byToken = [];
        foreach ($definitions as $definition) {
            $byName[$definition->name] = $definition;
            foreach ($definition->aliases as $alias) {
                $byToken[$alias] = $definition->name;
            }
        }

        $this->definitions = $byName;
        $this->byToken     = $byToken;
    }

    public static function shipped(): self
    {
        return new self(
            new ToolDefinitionDto('allCodingStandardsTools', ['allCS'], 'all coding standards tools', PhaseEnum::CodingStandards, false, isPhaseRunner: true),
            new ToolDefinitionDto('allLintingTools', ['allLints'], 'all linting tools', PhaseEnum::Linting, false, isPhaseRunner: true),
            new ToolDefinitionDto('allStaticAnalysisTools', ['allStatic'], 'all static analysis tools', PhaseEnum::StaticAnalysis, false, isPhaseRunner: true),
            new ToolDefinitionDto('allTestingTools', ['allTests'], 'all testing tools', PhaseEnum::Testing, false, isPhaseRunner: true),
            new ToolDefinitionDto('rector', ['r', 'rector'], 'Rector', PhaseEnum::CodingStandards, true, banner: 'Running Rector'),
            new ToolDefinitionDto('phpCsFixer', ['f', 'fixer', 'csfixer'], 'PHP-CS-Fixer', PhaseEnum::CodingStandards, true, banner: 'Running PHP-CS-Fixer'),
            new ToolDefinitionDto('psr4Validate', ['psr', 'psr4'], 'psr4 validation', PhaseEnum::Linting, false, banner: 'Validating PSR-4 Roots'),
            new ToolDefinitionDto('composerChecks', ['com', 'composer'], 'composer validation', PhaseEnum::Linting, false, banner: 'Checking for Composer Issues'),
            new ToolDefinitionDto('packageType', ['pt', 'packagetype', 'packageType'], 'assert composer.json declares an explicit package type (library/project/...)', PhaseEnum::Linting, false, banner: 'Checking Package Type Is Declared'),
            new ToolDefinitionDto('configTemplateIgnoreList', ['cti', 'configTemplateIgnoreList'], 'audit configDefaults/generic templates against psr4-validate-ignore-list.txt', PhaseEnum::Linting, false, banner: 'Auditing Config Template Ignore-List Coverage'),
            new ToolDefinitionDto('infectionConfigSourceDirs', ['icsd', 'infectionConfigSourceDirs'], "assert infection.json's source.directories resolve to real directories", PhaseEnum::Linting, false, banner: 'Checking Infection Config Source Directories Exist'),
            new ToolDefinitionDto('versionPins', ['vp', 'versionPins'], 'assert phpunit.xml, safe scan-files and GitHub Actions PHP pins match the installed PHPUnit / running PHP', PhaseEnum::Linting, false, banner: 'Checking Version Pins Match The Toolchain In Use'),
            new ToolDefinitionDto('phpStrictTypes', ['st', 'stricttypes'], 'strict types validation', PhaseEnum::Linting, true, banner: "Setting Strict Types If It's Missing"),
            new ToolDefinitionDto('phpLint', ['lint', 'phplint'], 'phplint', PhaseEnum::Linting, true, banner: 'Running PHP Lint'),
            new ToolDefinitionDto('composerRequireChecker', ['cr'], 'composer require checker', PhaseEnum::Linting, false, banner: 'Running Composer Require Checker'),
            new ToolDefinitionDto('composerDependencyAnalyser', ['cda'], 'unused, shadow and misplaced dependencies', PhaseEnum::Linting, false, banner: 'Running Composer Dependency Analyser'),
            new ToolDefinitionDto('markdownLinks', ['ml', 'markdown'], 'markdown validation', PhaseEnum::Linting, false, banner: 'Running Markdown Links Checker'),
            new ToolDefinitionDto('branchNamePolicy', ['bnp', 'branchNamePolicy'], 'Branch naming policy (PR convention)', PhaseEnum::StaticAnalysis, false, banner: 'Checking Branch Name Policy'),
            new ToolDefinitionDto('phpstanIgnoreJustification', ['pij', 'phpstanIgnoreJustification'], 'assert every ignoreErrors entry in qaConfig/phpstan.neon carries a usable justification', PhaseEnum::StaticAnalysis, false, banner: 'Checking PHPStan ignoreErrors Justifications'),
            new ToolDefinitionDto(self::PHPSTAN, ['stan', self::PHPSTAN], self::PHPSTAN, PhaseEnum::StaticAnalysis, true, ToolGateEnum::NotQuick, 'Running PHPStan', supportsJson: true),
            new ToolDefinitionDto('phpArkitect', ['arch', 'arkitect', 'phparkitect'], 'PHPArkitect architecture rules (on by default; useArkitect=0 to disable)', PhaseEnum::StaticAnalysis, false, banner: 'Running PHPArkitect (architecture rules)'),
            new ToolDefinitionDto('sensitiveParameterUsage', ['spu', 'sensitiveparameter', 'sensitiveParameterUsage'], 'assert #[\SensitiveParameter] is used somewhere in src/', PhaseEnum::StaticAnalysis, false, banner: 'Checking SensitiveParameter Usage'),
            new ToolDefinitionDto(self::PHPUNIT, ['unit', self::PHPUNIT], self::PHPUNIT, PhaseEnum::Testing, true, ToolGateEnum::NotQuick, 'Running PHPUnit Tests'),
            new ToolDefinitionDto(self::INFECTION, ['infect', self::INFECTION], self::INFECTION, PhaseEnum::Testing, false, ToolGateEnum::Infection, 'Running Infection (mutation testing)'),
            new ToolDefinitionDto('uniterate', ['uniterate'], 'phpunit iterative mode - prioritise broken tests and fail on error', null, false, target: self::PHPUNIT),
        );
    }

    /**
     * The lanes a platform adds to a phase, on top of the generic set. They
     * are not `-t` selectable and are not part of the frozen registry.
     *
     * @return list<ToolDefinitionDto>
     */
    public static function platformLanes(PlatformEnum $platform, PhaseEnum $phase): array
    {
        if (PlatformEnum::Symfony !== $platform || PhaseEnum::Linting !== $phase) {
            return [];
        }

        return [
            new ToolDefinitionDto('twigLint', [], 'Symfony twig linter', PhaseEnum::Linting, false, banner: 'Running Twig Linter'),
            new ToolDefinitionDto('yamlLint', [], 'Symfony yaml linter', PhaseEnum::Linting, false, banner: 'Running Yaml Linter'),
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
    public function toolsForPhase(PhaseEnum $phase): array
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
