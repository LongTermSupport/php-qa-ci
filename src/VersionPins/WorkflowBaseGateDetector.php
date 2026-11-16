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
 * through other jobs, under `needs:`. A job that only calls a reusable workflow
 * is expensive too: its steps are not in this file to be read.
 *
 * A workflow on `pull_request_target` alone is asked for the gate only when a
 * job installs or runs something, since one that only labels or comments costs
 * seconds. That event checks out the base branch, so its gate fetches the pull
 * request head without checking it out, and a gate conditioned on
 * `github.event_name == 'pull_request'` is reported: that step is skipped on
 * this event. A workflow on both events keeps the `pull_request` rule.
 *
 * Text-level, like its sibling {@see WorkflowPhpVersionDetector}: a workflow
 * is read for the shape of its jobs, not evaluated. It is read after its
 * comments are removed (a comment is not the thing it names), and its trigger
 * is read in every valid shape by {@see WorkflowTriggerReader}. A trigger that
 * cannot be reduced to event names is reported, never passed, and so is a
 * workflow on either event whose jobs cannot be found: a `jobs` key that is
 * missing, empty, a flow mapping, an alias or anchored. A key written with spaces or a tab before
 * its colon (`jobs :`, `qa :`, `needs :`) is the same key and is read. An `on` key, a `jobs` key or a
 * job id written twice is reported by name: GitHub rejects it and YAML parsers disagree on which
 * copy applies, so judging the first would pass the second unread.
 *
 * @internal
 */
final readonly class WorkflowBaseGateDetector
{
    private const string EVENT_PULL_REQUEST = 'pull_request';

    private const string EVENT_PULL_REQUEST_TARGET = 'pull_request_target';

    private const string PULL_REQUEST_ONLY_CONDITION = '/github\.event_name\s*==\s*\'pull_request\'|\'pull_request\'\s*==\s*github\.event_name/i';

    private const string NO_GATE = 'runs on pull_request but has no up-to-date-with-base gate: add a step that fails when `git merge-base --is-ancestor "origin/${BASE_REF}" "${HEAD_SHA}"` is false, with BASE_REF set from github.event.pull_request.base.ref';

    private const string NO_GATE_TARGET = 'runs on pull_request_target and installs or runs code but has no up-to-date-with-base gate: add a step that fetches the base branch and the pull request head without checking the head out (`git fetch origin "${BASE_REF}"`, then `git fetch origin "pull/${PR_NUMBER}/head"`) and fails when `git merge-base --is-ancestor "origin/${BASE_REF}" FETCH_HEAD` is false, with BASE_REF set from github.event.pull_request.base.ref and PR_NUMBER from github.event.pull_request.number; do not condition the step on `github.event_name == \'pull_request\'`, which is false on this event';

    private const string GATE_NEVER_RUNS = "its up-to-date-with-base gate is conditioned on `github.event_name == 'pull_request'`, which is false on pull_request_target, so the gate never runs: drop the condition or name pull_request_target in it";

    private const string UNREADABLE_TRIGGER = 'its `on` trigger is written in a shape this check cannot read, so whether it runs on pull_request or pull_request_target is unknown: write it as a scalar (`on: pull_request`), a list (`on: [push, pull_request]` or a block list) or a mapping (`on:` then `  pull_request:`), without an anchor, an alias or a merge key';

    private const string UNREADABLE_JOBS = 'its `jobs` key is missing, empty or written in a shape this check cannot read, so whether its jobs are gated is unknown: write `jobs:` on a line of its own, then each job id as an indented key, without a flow mapping, an anchor or an alias';

    private const string DUPLICATE_KEY = 'its `%s` key is written more than once, which GitHub rejects and YAML parsers read differently, so which copy applies is unknown: keep one';

    private const string DUPLICATE_JOB = 'the job id "%s" is written more than once under `jobs`, which GitHub rejects and YAML parsers read differently, so which copy applies is unknown: give each job its own id';

    private const string KEY_ON = 'on';

    private const string KEY_JOBS = 'jobs';

    private const string JOBS_KEY = '/^(?:jobs|"jobs"|\'jobs\')[ \t]*:$/';

    private const string JOBS_KEY_WITH_VALUE = '/^(?:jobs|"jobs"|\'jobs\')[ \t]*:(?:[ \t].*)?$/';

    private const string JOB_HEADER = '/^(?:"[A-Za-z0-9_-]+"|\'[A-Za-z0-9_-]+\'|[A-Za-z0-9_-]+)[ \t]*:$/';

    private const string NEEDS_LINE = '/^needs[ \t]*:(.*)$/';

    private const string USES_LINE = '/^uses[ \t]*:/';

    private const string QA_AT_END_OF_LINE = '/qa';

    private const string GATE_BASE_REF = 'github.event.pull_request.base.ref';

    private const string GATE_TEST = 'merge-base --is-ancestor';

    private const array EXPENSIVE_MARKERS = ['shivammathur/setup-php', 'composer install', 'composer qa', '/qa"', "/qa'", '/qa '];

    public function __construct(
        private WorkflowYamlText $text = new WorkflowYamlText(),
        private WorkflowTriggerReader $triggers = new WorkflowTriggerReader(),
    ) {
    }

    /**
     * @param string $workflowYaml a workflow file's contents
     *
     * @return list<string> one message per defect; empty when the workflow runs
     *                      on neither pull_request nor pull_request_target, or
     *                      gates every expensive job. A trigger or a `jobs` key
     *                      this check cannot read is a defect, not a pass.
     */
    public function check(string $workflowYaml): array
    {
        $text = $this->text->withoutComments($workflowYaml);
        if ($this->triggers->occurrences($text) > 1) {
            return [\sprintf(self::DUPLICATE_KEY, self::KEY_ON)];
        }

        $events = $this->triggers->events($text);
        if (null === $events) {
            return [self::UNREADABLE_TRIGGER];
        }

        $onPullRequest = \in_array(self::EVENT_PULL_REQUEST, $events, true);
        $onTarget      = \in_array(self::EVENT_PULL_REQUEST_TARGET, $events, true);
        if (!$onPullRequest && !$onTarget) {
            return [];
        }

        if ($this->jobsKeyCount($text) > 1) {
            return [\sprintf(self::DUPLICATE_KEY, self::KEY_JOBS)];
        }

        $jobs = $this->jobs($text);
        if ([] === $jobs) {
            return [self::UNREADABLE_JOBS];
        }

        $repeated = $this->firstRepeatedId(...$jobs);
        if (null !== $repeated) {
            return [\sprintf(self::DUPLICATE_JOB, $repeated)];
        }

        $gates   = array_values(array_filter($jobs, $this->hasGate(...)));
        $gateIds = array_map(static fn (array $job): string => $job['id'], $gates);
        if ([] === $gates) {
            if ($onPullRequest) {
                return [self::NO_GATE];
            }

            return array_any($jobs, $this->isExpensive(...)) ? [self::NO_GATE_TARGET] : [];
        }

        $event    = $onPullRequest ? self::EVENT_PULL_REQUEST : self::EVENT_PULL_REQUEST_TARGET;
        $needs    = $this->needsMap(...$jobs);
        $problems = [];
        foreach ($jobs as $job) {
            if (!$this->isExpensive($job)) {
                continue;
            }

            if (\in_array($job['id'], $gateIds, true)) {
                if ($this->gatePosition($job['body']) > $this->firstExpensivePosition($job)) {
                    $problems[] = \sprintf('job "%s" runs its gate after its first expensive step; move the gate ahead of PHP setup and composer install', $job['id']);
                }

                continue;
            }

            if (!$this->needsAny($job['id'], $needs, ...$gateIds)) {
                $problems[] = \sprintf('job "%s" runs on %s without depending on the up-to-date-with-base gate; add the gate job (%s) to its needs', $job['id'], $event, implode(', ', $gateIds));
            }
        }

        if ($onTarget && array_any($gates, $this->skippedOnPullRequestTarget(...))) {
            $problems[] = self::GATE_NEVER_RUNS;
        }

        return $problems;
    }

    /** How many times the `jobs` key is written at the start of a line, with or without a value after the colon. */
    private function jobsKeyCount(string $text): int
    {
        return \count(array_filter(explode("\n", $text), static fn (string $line): bool => 1 === \Safe\preg_match(self::JOBS_KEY_WITH_VALUE, $line)));
    }

    /**
     * The first job id that two jobs share.
     *
     * @param array{id: string, body: string, indent: int} ...$jobs
     */
    private function firstRepeatedId(array ...$jobs): ?string
    {
        $seen = [];
        foreach ($jobs as $job) {
            if (\in_array($job['id'], $seen, true)) {
                return $job['id'];
            }

            $seen[] = $job['id'];
        }

        return null;
    }

    /**
     * A gate conditioned on the pull_request event alone is skipped when the event is pull_request_target.
     *
     * @param array{id: string, body: string, indent: int} $gateJob
     */
    private function skippedOnPullRequestTarget(array $gateJob): bool
    {
        return 1 === \Safe\preg_match(self::PULL_REQUEST_ONLY_CONDITION, $gateJob['body'])
            && !str_contains(strtolower($gateJob['body']), self::EVENT_PULL_REQUEST_TARGET);
    }

    /**
     * Each job's id, its text and the indentation of its own keys, in file order. Empty when a job
     * cannot be read: an entry at the jobs' own indentation that is not an id alone on its line (a
     * flow mapping, an alias, an anchor, a value) would be judged as part of the job before it, or
     * not at all. A list item at that indentation is no entry of the mapping and stays with the
     * job before it.
     *
     * @return list<array{id: string, body: string, indent: int}>
     */
    private function jobs(string $text): array
    {
        $lines = explode("\n", $text);
        $start = null;
        foreach ($lines as $index => $line) {
            if (1 === \Safe\preg_match(self::JOBS_KEY, $line)) {
                $start = $index;

                break;
            }
        }

        if (null === $start) {
            return [];
        }

        /** @var list<array{id: string, body: string}> $found */
        $found        = [];
        $headerIndent = null;
        foreach (\array_slice($lines, $start + 1) as $line) {
            if ('' === trim($line)) {
                $this->appendToLast($found, '');

                continue;
            }

            $indent = $this->text->indentOf($line);
            if (0 === $indent) {
                break;
            }

            $headerIndent ??= $indent;
            if ($indent === $headerIndent) {
                if (1 === \Safe\preg_match(self::JOB_HEADER, trim($line))) {
                    $found[] = ['id' => $this->text->unquote(substr(trim($line), 0, -1)), 'body' => ''];

                    continue;
                }

                if (!str_starts_with(trim($line), '-')) {
                    return [];
                }
            }

            $this->appendToLast($found, $line);
        }

        return array_map(fn (array $job): array => ['id' => $job['id'], 'body' => $job['body'], 'indent' => $this->keyIndent($job['body'])], $found);
    }

    /** @param list<array{id: string, body: string}> $jobs */
    private function appendToLast(array &$jobs, string $line): void
    {
        $last = array_key_last($jobs);
        if (null !== $last) {
            $jobs[$last]['body'] .= $line . "\n";
        }
    }

    /** The indentation of the first line a job's text holds, which is where its own keys are. */
    private function keyIndent(string $body): int
    {
        foreach (explode("\n", $body) as $line) {
            if ('' !== trim($line)) {
                return $this->text->indentOf($line);
            }
        }

        return 0;
    }

    /** @param array{id: string, body: string, indent: int} $job */
    private function hasGate(array $job): bool
    {
        return str_contains($job['body'], self::GATE_BASE_REF) && str_contains($job['body'], self::GATE_TEST);
    }

    /** @param array{id: string, body: string, indent: int} $job */
    private function isExpensive(array $job): bool
    {
        return \PHP_INT_MAX !== $this->firstExpensivePosition($job);
    }

    /** @param array{id: string, body: string, indent: int} $job */
    private function firstExpensivePosition(array $job): int
    {
        $first = \PHP_INT_MAX;
        foreach (self::EXPENSIVE_MARKERS as $marker) {
            $position = strpos($job['body'], $marker);
            if (false !== $position) {
                $first = min($first, $position);
            }
        }

        $offset = 0;
        foreach (explode("\n", $job['body']) as $line) {
            if ($this->text->indentOf($line) === $job['indent'] && 1 === \Safe\preg_match(self::USES_LINE, ltrim($line))) {
                $first = min($first, $offset);
            }

            if (str_ends_with($line, self::QA_AT_END_OF_LINE)) {
                $first = min($first, $offset + \strlen($line) - \strlen(self::QA_AT_END_OF_LINE));
            }

            $offset += \strlen($line) + 1;
        }

        return $first;
    }

    private function gatePosition(string $jobBody): int
    {
        $position = strpos($jobBody, self::GATE_TEST);

        return false === $position ? \PHP_INT_MAX : $position;
    }

    /**
     * @param array{id: string, body: string, indent: int} ...$jobs
     *
     * @return array<array-key, list<string>> job id => the job ids it lists under needs
     */
    private function needsMap(array ...$jobs): array
    {
        $map = [];
        foreach ($jobs as $job) {
            $map[$job['id']] = $this->needsOf($job);
        }

        return $map;
    }

    /**
     * The job ids a job lists under its own `needs:` key, as a scalar, a flow list on one line or
     * several, or a block list.
     *
     * @param array{id: string, body: string, indent: int} $job
     *
     * @return list<string>
     */
    private function needsOf(array $job): array
    {
        $lines = explode("\n", $job['body']);
        foreach ($lines as $index => $line) {
            if ($this->text->indentOf($line) !== $job['indent'] || 1 !== \Safe\preg_match(self::NEEDS_LINE, ltrim($line), $match) || !isset($match[1])) {
                continue;
            }

            $rest      = trim($match[1]);
            $following = \array_slice($lines, $index + 1);
            if ('' === $rest) {
                return $this->blockNames($job['indent'], ...$following);
            }

            if ('[' === $rest[0]) {
                $balanced = $this->text->balanced($rest . "\n" . implode("\n", $following));

                return null === $balanced ? [] : array_map($this->text->unquote(...), $this->text->flowItems($balanced));
            }

            return [$this->text->unquote($rest)];
        }

        return [];
    }

    /**
     * The names in the block list that follows a bare `needs:`, at the job's own indentation or deeper.
     *
     * @param string ...$lines the lines after the `needs:` line
     *
     * @return list<string>
     */
    private function blockNames(int $keyIndent, string ...$lines): array
    {
        $names = [];
        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if ($this->text->indentOf($line) < $keyIndent || !str_starts_with(ltrim($line), '-')) {
                break;
            }

            $names[] = $this->text->unquote(substr(ltrim($line), 1));
        }

        return $names;
    }

    /**
     * @param array<array-key, list<string>> $needs
     */
    private function needsAny(string $job, array $needs, string ...$targets): bool
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
