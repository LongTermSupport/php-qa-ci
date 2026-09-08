<?php

declare(strict_types=1);

namespace LTS\PHPQA\GithubActions;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;

/**
 * Pipeline lane: every GitHub Actions workflow under .github/workflows/ and
 * every shipped template under templates/github-actions/ that detects a PHP
 * version from composer.json must be able to select, and default to, the
 * version composer.json requires. When both the shipped consumer template
 * and the workflow this repository runs exist, they must be identical. Thin
 * I/O around {@see WorkflowPhpVersionDetector}.
 *
 * @internal
 */
final readonly class WorkflowPhpVersionCheck
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.githubActionsPhpVersion';

    private const string WORKFLOWS_DIR = '/.github/workflows';

    private const string TEMPLATES_DIR = '/templates/github-actions';

    private const string SHIPPED_TEMPLATE = self::TEMPLATES_DIR . '/php-qa-ci.yml';

    private const string RUN_WORKFLOW = self::WORKFLOWS_DIR . '/qa.yml';

    public function __construct(
        private WorkflowPhpVersionDetector $detector = new WorkflowPhpVersionDetector(),
    ) {
    }

    public function run(string $projectRoot): int
    {
        $required = $this->requiredPhpVersion($projectRoot);
        if (null === $required) {
            echo 'composer.json declares no PHP major.minor requirement — nothing for workflows to detect, skipping.' . \PHP_EOL;

            return 0;
        }

        $problems = [];
        $checked  = 0;
        foreach ($this->workflows($projectRoot) as $relative => $yaml) {
            if (!$this->detector->detectsPhpVersion($yaml)) {
                continue;
            }

            ++$checked;
            foreach ($this->detector->check($yaml, $required) as $problem) {
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

        if ([] === $problems) {
            echo \sprintf(
                'GitHub Actions PHP version detection can select PHP %s in every detecting workflow — OK (%d checked).',
                $required,
                $checked,
            ) . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — a GitHub Actions workflow cannot select the PHP version composer.json requires' . \PHP_EOL
            . '---------------------------------------------------------------------------------------' . \PHP_EOL
            . 'Required PHP (composer.json): ' . $required . \PHP_EOL . \PHP_EOL;
        foreach ($problems as $problem) {
            echo '  - ' . $problem . \PHP_EOL;
        }

        echo \PHP_EOL . '🪪  ' . self::IDENTIFIER . '  (vendor/bin/rule-doc ' . self::IDENTIFIER . ')' . \PHP_EOL;

        return 1;
    }

    public static function main(string $projectRoot): int
    {
        return new self()->run($projectRoot);
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
