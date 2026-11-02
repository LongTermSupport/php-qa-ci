<?php

declare(strict_types=1);

namespace LTS\PHPQA\VersionPins;

/**
 * Pure decision: a GitHub Actions workflow that runs on `pull_request` must
 * stop at once when the pull request head is behind its base branch, before
 * any job that installs or runs anything.
 *
 * The gate is a step whose script reads `github.event.pull_request.base.ref`
 * and tests `git merge-base --is-ancestor`. It either sits in the same job
 * ahead of the first expensive step (PHP setup, `composer install`, a `qa`
 * run), or in a job of its own that every expensive job lists, directly or
 * through other jobs, under `needs:`.
 *
 * Text-level, like its sibling {@see WorkflowPhpVersionDetector}: a workflow
 * is read for the shape of its jobs, not evaluated.
 *
 * @internal
 */
final readonly class WorkflowBaseGateDetector
{
    private const string TRIGGER_PATTERN = '/^(?:on:.*\bpull_request\b|[ \t]{2}pull_request:)/m';

    private const string JOB_HEADER_PATTERN = '/^  ([A-Za-z0-9_-]+):[ \t]*$/m';

    private const string NEEDS_PATTERN = '/^    needs:[ \t]*(.+)$/m';

    private const string GATE_BASE_REF = 'github.event.pull_request.base.ref';

    private const string GATE_TEST = 'merge-base --is-ancestor';

    /** @var list<string> */
    private const array EXPENSIVE_MARKERS = ['shivammathur/setup-php', 'composer install', '/qa"', '/qa '];

    /** Whether the workflow runs on pull_request at all. */
    public function runsOnPullRequest(string $workflowYaml): bool
    {
        return 1 === \Safe\preg_match(self::TRIGGER_PATTERN, $workflowYaml);
    }

    /**
     * @param string $workflowYaml a workflow file's contents
     *
     * @return list<string> one message per defect; empty when the workflow does
     *                      not run on pull_request, or gates every expensive job
     */
    public function check(string $workflowYaml): array
    {
        if (!$this->runsOnPullRequest($workflowYaml)) {
            return [];
        }

        $jobs = $this->jobs($workflowYaml);
        if ([] === $jobs) {
            return [];
        }

        $gateJobs = array_keys(array_filter($jobs, $this->hasGate(...)));
        if ([] === $gateJobs) {
            return ['runs on pull_request but has no up-to-date-with-base gate: add a step that fails when `git merge-base --is-ancestor "origin/${BASE_REF}" "${HEAD_SHA}"` is false, with BASE_REF set from github.event.pull_request.base.ref'];
        }

        $needs    = $this->needsMap($jobs);
        $problems = [];
        foreach ($jobs as $id => $body) {
            if (!$this->isExpensive($body)) {
                continue;
            }

            if (\in_array($id, $gateJobs, true)) {
                if ($this->gatePosition($body) > $this->firstExpensivePosition($body)) {
                    $problems[] = \sprintf('job "%s" runs its gate after its first expensive step; move the gate ahead of PHP setup and composer install', $id);
                }

                continue;
            }

            if (!$this->needsAny($id, $gateJobs, $needs)) {
                $problems[] = \sprintf('job "%s" runs on pull_request without depending on the up-to-date-with-base gate; add the gate job (%s) to its needs', $id, implode(', ', $gateJobs));
            }
        }

        return $problems;
    }

    /** @return array<string, string> job id => the job's text */
    private function jobs(string $workflowYaml): array
    {
        $start = strpos($workflowYaml, "\njobs:");
        if (false === $start) {
            return [];
        }

        $parts = \Safe\preg_split(self::JOB_HEADER_PATTERN, substr($workflowYaml, $start + 1), -1, \PREG_SPLIT_DELIM_CAPTURE);
        $jobs  = [];
        for ($i = 1; $i + 1 < \count($parts); $i += 2) {
            $jobs[$parts[$i]] = $parts[$i + 1];
        }

        return $jobs;
    }

    private function hasGate(string $jobBody): bool
    {
        return str_contains($jobBody, self::GATE_BASE_REF) && str_contains($jobBody, self::GATE_TEST);
    }

    private function isExpensive(string $jobBody): bool
    {
        return \PHP_INT_MAX !== $this->firstExpensivePosition($jobBody);
    }

    private function firstExpensivePosition(string $jobBody): int
    {
        $first = \PHP_INT_MAX;
        foreach (self::EXPENSIVE_MARKERS as $marker) {
            $position = strpos($jobBody, $marker);
            if (false !== $position) {
                $first = min($first, $position);
            }
        }

        return $first;
    }

    private function gatePosition(string $jobBody): int
    {
        $position = strpos($jobBody, self::GATE_TEST);

        return false === $position ? \PHP_INT_MAX : $position;
    }

    /**
     * @param array<string, string> $jobs
     *
     * @return array<string, list<string>> job id => the job ids it lists under needs
     */
    private function needsMap(array $jobs): array
    {
        $map = [];
        foreach ($jobs as $id => $body) {
            $map[$id] = [];
            if (1 !== \Safe\preg_match(self::NEEDS_PATTERN, $body, $matches)) {
                continue;
            }

            foreach (\Safe\preg_split('/[\s,\[\]]+/', $matches[1], -1, \PREG_SPLIT_NO_EMPTY) as $name) {
                $map[$id][] = $name;
            }
        }

        return $map;
    }

    /**
     * @param list<string>                $targets
     * @param array<string, list<string>> $needs
     */
    private function needsAny(string $job, array $targets, array $needs): bool
    {
        $seen  = [];
        $queue = [$job];
        while ([] !== $queue) {
            $current = array_pop($queue);
            foreach ($needs[$current] ?? [] as $dependency) {
                if (\in_array($dependency, $targets, true)) {
                    return true;
                }

                if (!isset($seen[$dependency])) {
                    $seen[$dependency] = true;
                    $queue[]           = $dependency;
                }
            }
        }

        return false;
    }
}
