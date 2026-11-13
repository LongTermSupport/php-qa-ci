<?php

declare(strict_types=1);

namespace LTS\PHPQA\VersionPins;

/**
 * Pure decision: a workflow that selects pipeline phases by group (`qa -t
 * allCS`, `qa -t allStatic`) must run every phase group the pipeline defines,
 * or say in a comment why one is left out.
 *
 * A workflow that runs the whole `qa`, or single tools, selects no phase group
 * and is not judged. One that runs some groups reads as complete coverage, so
 * an omitted group (the linting phase, left out of a gate) is a green check
 * that did not run what its name suggests. The group list is passed in, read
 * from the pipeline's phases, so a phase added later is required on the day it
 * exists.
 *
 * A group is left out on purpose with a comment line
 * `# qa-phase-deferred: <group> - <reason>`; the separator may be `-`, `--`,
 * `—` or `:`. A comment that merely mentions a group does not run it.
 *
 * Text-level, like its siblings {@see WorkflowBaseGateDetector} and
 * {@see WorkflowPhpVersionDetector}: a workflow is read, not evaluated.
 *
 * @internal
 */
final readonly class WorkflowPhaseGroupsDetector
{
    private const string TARGET_PATTERN = '/(?<![A-Za-z0-9])-t[ \t]+[\'"]?([A-Za-z0-9]+)/';

    private const string DEFERRAL_PATTERN = '/^[ \t]*#[ \t]*qa-phase-deferred:[ \t]*([A-Za-z0-9]+)[ \t]*(?:—|--|-|:)[ \t]*(\S.*)$/u';

    /**
     * @param string $workflowYaml a workflow file's contents
     * @param string ...$groups    the `-t` token that runs each phase, e.g. `allLints`
     *
     * @return list<string> one message per group left out; empty when the workflow selects no
     *                      phase group, or runs or defers every one
     */
    public function check(string $workflowYaml, string ...$groups): array
    {
        [$code, $comments] = $this->split($workflowYaml);

        $run = $this->targets($code);
        if ([] === array_intersect($groups, $run)) {
            return [];
        }

        $deferred = $this->deferred(...$comments);
        $problems = [];
        foreach ($groups as $group) {
            if (\in_array($group, $run, true) || \in_array($group, $deferred, true)) {
                continue;
            }

            $problems[] = \sprintf(
                'runs qa phase groups but not %1$s: add a step that runs `qa -t %1$s`, or say why it is left out with a comment line `# qa-phase-deferred: %1$s - <reason>`',
                $group,
            );
        }

        return $problems;
    }

    /** @return array{0: string, 1: list<string>} the workflow without its comment lines, and those lines */
    private function split(string $workflowYaml): array
    {
        $code     = [];
        $comments = [];
        foreach (explode("\n", $workflowYaml) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                $comments[] = $line;

                continue;
            }

            $code[] = $line;
        }

        return [implode("\n", $code), $comments];
    }

    /** @return list<string> every word that follows `-t` */
    private function targets(string $code): array
    {
        $matches = [];
        \Safe\preg_match_all(self::TARGET_PATTERN, $code, $matches);

        $words = $matches[1] ?? [];

        return \is_array($words) ? array_values(array_filter($words, is_string(...))) : [];
    }

    /**
     * @return list<string> the groups a comment declares left out, with a reason
     */
    private function deferred(string ...$comments): array
    {
        $groups = [];
        foreach ($comments as $comment) {
            if (1 === \Safe\preg_match(self::DEFERRAL_PATTERN, $comment, $matches) && isset($matches[1])) {
                $groups[] = $matches[1];
            }
        }

        return $groups;
    }
}
