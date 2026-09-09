<?php

declare(strict_types=1);

namespace LTS\PHPQA\VersionPins;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use PHPUnit\Runner\Version;

/**
 * Pipeline lane: every version pin in the project's QA configuration must
 * match the toolchain actually in use. One lane, three pins:
 *
 *  - phpunit.xml's schema URL and SYMFONY_PHPUNIT_VERSION against the
 *    installed PHPUnit major ({@see PhpUnitConfigDetector});
 *  - composer-require-checker's thecodingmachine/safe scan-files against the
 *    generated files safe loads on the running PHP ({@see SafeScanFilesDetector});
 *  - GitHub Actions workflows' PHP version detection against the PHP
 *    composer.json requires ({@see WorkflowPhpVersionDetector}).
 *
 * None of these pins fails a test run when stale, so nothing else notices when
 * the PHP or PHPUnit requirement moves on. Thin I/O around the detectors.
 *
 * @internal
 */
final readonly class VersionPinsCheck
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.versionPins';

    private const string CRC_LABEL = 'composerRequireChecker.json (';

    private const string WORKFLOWS_DIR = '/.github/workflows';

    private const string TEMPLATES_DIR = '/templates/github-actions';

    private const string SHIPPED_TEMPLATE = self::TEMPLATES_DIR . '/php-qa-ci.yml';

    private const string RUN_WORKFLOW = self::WORKFLOWS_DIR . '/qa.yml';

    public function __construct(
        private string $installedPhpUnitVersion,
        private string $phpMajorMinor,
        private PhpUnitConfigDetector $phpUnitConfigDetector = new PhpUnitConfigDetector(),
        private SafeScanFilesDetector $safeScanFilesDetector = new SafeScanFilesDetector(),
        private WorkflowPhpVersionDetector $workflowDetector = new WorkflowPhpVersionDetector(),
    ) {
    }

    /**
     * @param string $projectRoot                the project being checked
     * @param string $phpUnitConfigPath          the resolved phpunit.xml the PHPUnit lane runs with
     * @param string $composerRequireCheckerPath the resolved composerRequireChecker.json the cr lane runs with
     */
    public function run(string $projectRoot, string $phpUnitConfigPath, string $composerRequireCheckerPath): int
    {
        $problems = [
            ...$this->phpUnitConfigProblems($phpUnitConfigPath),
            ...$this->safeScanFilesProblems($composerRequireCheckerPath, $projectRoot),
            ...$this->workflowProblems($projectRoot),
        ];

        if ([] === $problems) {
            echo \sprintf(
                'Version pins agree with the toolchain in use (PHPUnit %s, PHP %s): phpunit.xml, safe scan-files, GitHub Actions workflows — OK.',
                $this->installedPhpUnitVersion,
                $this->phpMajorMinor,
            ) . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — a version pin in the QA configuration does not match the toolchain in use' . \PHP_EOL
            . '----------------------------------------------------------------------------------' . \PHP_EOL
            . 'Installed: PHPUnit ' . $this->installedPhpUnitVersion . ', PHP ' . $this->phpMajorMinor . \PHP_EOL . \PHP_EOL;
        foreach ($problems as $problem) {
            echo '  - ' . $problem . \PHP_EOL;
        }

        echo \PHP_EOL . '🪪  ' . self::IDENTIFIER . '  (vendor/bin/rule-doc ' . self::IDENTIFIER . ')' . \PHP_EOL;

        return 1;
    }

    public static function main(string $projectRoot, string $phpUnitConfigPath, string $composerRequireCheckerPath): int
    {
        return new self(Version::id(), \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION)
            ->run($projectRoot, $phpUnitConfigPath, $composerRequireCheckerPath)
        ;
    }

    /** @return list<string> */
    private function phpUnitConfigProblems(string $configPath): array
    {
        if (!is_file($configPath)) {
            return ['phpunit.xml: config path does not exist: ' . $configPath];
        }

        return array_map(
            static fn (string $problem): string => 'phpunit.xml (' . $configPath . '): ' . $problem,
            $this->phpUnitConfigDetector->check(\Safe\file_get_contents($configPath), $this->installedPhpUnitVersion),
        );
    }

    /** @return list<string> */
    private function safeScanFilesProblems(string $configPath, string $projectRoot): array
    {
        if (!is_file($configPath)) {
            return ['composerRequireChecker.json: config path does not exist: ' . $configPath];
        }

        try {
            $decoded = \Safe\json_decode(\Safe\file_get_contents($configPath), true);
        } catch (\Safe\Exceptions\JsonException $jsonException) {
            return [self::CRC_LABEL . $configPath . '): not valid JSON (' . $jsonException->getMessage() . ')'];
        }

        $scanFiles = \is_array($decoded) ? ($decoded['scan-files'] ?? []) : [];
        if (!\is_array($scanFiles)) {
            return [self::CRC_LABEL . $configPath . '): "scan-files" is not a list'];
        }

        return array_map(
            static fn (string $problem): string => self::CRC_LABEL . $configPath . '): ' . $problem,
            $this->safeScanFilesDetector->check($projectRoot, $this->phpMajorMinor, ...array_values(array_filter($scanFiles, is_string(...)))),
        );
    }

    /** @return list<string> */
    private function workflowProblems(string $projectRoot): array
    {
        $required = $this->requiredPhpVersion($projectRoot);
        if (null === $required) {
            return [];
        }

        $problems = [];
        foreach ($this->workflows($projectRoot) as $relative => $yaml) {
            if (!$this->workflowDetector->detectsPhpVersion($yaml)) {
                continue;
            }

            foreach ($this->workflowDetector->check($yaml, $required) as $problem) {
                $problems[] = $relative . ': ' . $problem;
            }
        }

        $template = $projectRoot . self::SHIPPED_TEMPLATE;
        $workflow = $projectRoot . self::RUN_WORKFLOW;
        if (is_file($template) && is_file($workflow) && \Safe\file_get_contents($template) !== \Safe\file_get_contents($workflow)) {
            $problems[] = \sprintf(
                '%s differs from %s; the shipped consumer template must stay identical to the workflow this repository runs',
                ltrim(self::SHIPPED_TEMPLATE, '/'),
                ltrim(self::RUN_WORKFLOW, '/'),
            );
        }

        return $problems;
    }

    /** The major.minor from composer.json's php constraint, or null when absent. */
    private function requiredPhpVersion(string $projectRoot): ?string
    {
        $composerJson = $projectRoot . '/composer.json';
        if (!is_file($composerJson)) {
            return null;
        }

        $composer = \Safe\json_decode(\Safe\file_get_contents($composerJson), true);
        if (!\is_array($composer)) {
            return null;
        }

        $require = $composer['require'] ?? null;
        if (!\is_array($require)) {
            return null;
        }

        $constraint = $require['php'] ?? null;
        if (!\is_string($constraint) || 1 !== \Safe\preg_match('/(\d+\.\d+)/', $constraint, $matches) || !isset($matches[1])) {
            return null;
        }

        return $matches[1];
    }

    /** @return array<string, string> project-relative path => contents */
    private function workflows(string $projectRoot): array
    {
        $files = [];
        foreach ([self::WORKFLOWS_DIR, self::TEMPLATES_DIR] as $dir) {
            $absolute = $projectRoot . $dir;
            if (!is_dir($absolute)) {
                continue;
            }

            foreach (\Safe\glob($absolute . '/*.yml') as $path) {
                if (!\is_string($path)) {
                    continue;
                }

                $files[ltrim($dir, '/') . '/' . basename($path)] = \Safe\file_get_contents($path);
            }
        }

        return $files;
    }
}
