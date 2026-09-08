<?php

declare(strict_types=1);

namespace LTS\PHPQA\GithubActions;

/**
 * Pure decision: every GitHub Actions workflow that derives a PHP version from
 * composer.json's constraint must be able to select the version the project
 * requires, and must fall back to it.
 *
 * The detection in a workflow is a hand-written list of versions, in one of
 * two shapes:
 *
 *   for V in 8.5 8.4 8.3; do ... done          with a PHP_VERSION=8.5 default
 *   if [[ "$PHP_CONSTRAINT" == *"8.5"* ]] ...  with a PHP_VERSION="8.5" default
 *
 * A PHP bump that updates composer.json but not every list leaves a workflow
 * that quietly selects an OLDER PHP; nothing fails, the wrong runtime runs.
 *
 * @internal
 */
final readonly class WorkflowPhpVersionDetector
{
    private const string LOOP_PATTERN = '/for V in ((?:\d+\.\d+\s*)+); do/';

    private const string CHAIN_ARM_PATTERN = '/\$PHP_CONSTRAINT" == \*"(\d+\.\d+)"\*/';

    private const string DEFAULT_PATTERN = '/^\s*PHP_VERSION="?(\d+\.\d+)"?\s*$/m';

    private const string CHAIN_ARM_ASSIGNMENT_PATTERN = '/== \*"(\d+\.\d+)"\* \]\]; then\s*\n\s*PHP_VERSION="(\d+\.\d+)"/';

    /**
     * @param string $workflowYaml    a workflow file's contents
     * @param string $requiredVersion the major.minor composer.json requires, e.g. "8.5"
     *
     * @return list<string> one message per version list or fallback default that
     *                      cannot select the required version; empty when the
     *                      workflow detects nothing, or detects correctly
     */
    public function check(string $workflowYaml, string $requiredVersion): array
    {
        $problems = [];

        foreach ($this->versionLists($workflowYaml) as $list) {
            if (!\in_array($requiredVersion, $list, true)) {
                $problems[] = \sprintf(
                    'the PHP version detection list [%s] cannot select PHP %s, which composer.json requires; add %s to the list',
                    implode(', ', $list),
                    $requiredVersion,
                    $requiredVersion,
                );
            }
        }

        foreach ($this->fallbackDefaults($workflowYaml) as $default) {
            if ($default !== $requiredVersion) {
                $problems[] = \sprintf(
                    'the fallback PHP version is %s but composer.json requires %s; set the default to %s',
                    $default,
                    $requiredVersion,
                    $requiredVersion,
                );
            }
        }

        return $problems;
    }

    /** Whether the workflow carries a PHP version detection at all. */
    public function detectsPhpVersion(string $workflowYaml): bool
    {
        return [] !== $this->versionLists($workflowYaml);
    }

    /**
     * Every version list in the file: each `for V in ...; do` loop, and the
     * set of `*"x.y"*` arms of an if/elif chain.
     *
     * @return list<list<string>>
     */
    private function versionLists(string $workflowYaml): array
    {
        $lists = [];

        foreach ($this->matchAll(self::LOOP_PATTERN, $workflowYaml, 1) as $loop) {
            $lists[] = array_values(array_filter(explode(' ', trim($loop)), static fn (string $v): bool => '' !== $v));
        }

        $arms = $this->matchAll(self::CHAIN_ARM_PATTERN, $workflowYaml, 1);
        if ([] !== $arms) {
            $lists[] = $arms;
        }

        return $lists;
    }

    /**
     * Every fallback default: a `PHP_VERSION=x.y` assignment that is not the
     * body of a matching if/elif arm.
     *
     * @return list<string>
     */
    private function fallbackDefaults(string $workflowYaml): array
    {
        $armValues = array_count_values($this->matchAll(self::CHAIN_ARM_ASSIGNMENT_PATTERN, $workflowYaml, 2));
        $defaults  = [];
        foreach ($this->matchAll(self::DEFAULT_PATTERN, $workflowYaml, 1) as $version) {
            if (($armValues[$version] ?? 0) > 0) {
                --$armValues[$version];

                continue;
            }

            $defaults[] = $version;
        }

        return $defaults;
    }

    /**
     * Every capture of one group across all matches, as a typed list.
     *
     * @return list<string>
     */
    private function matchAll(string $pattern, string $subject, int $group): array
    {
        \Safe\preg_match_all($pattern, $subject, $matches);
        $captures = $matches[$group] ?? [];
        if (!\is_array($captures)) {
            return [];
        }

        return array_values(array_filter($captures, is_string(...)));
    }
}
