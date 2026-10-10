<?php

declare(strict_types=1);

namespace LTS\PHPQA\IssueMonitor;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;
use RuntimeException;
use Safe\Exceptions\JsonException;

/**
 * Asks GitHub which open issues are new work for the issue loop. Who counts as
 * an approved author is not decided here: the hooks daemon's
 * `issue-validity --list-eligible` reads the one list, in
 * `.claude/hooks-daemon.yaml`. This class adds only "assigned to nobody yet",
 * which is how the loop marks an issue it has taken.
 *
 * A failed command, or an answer in a shape this class does not recognise,
 * throws a RuntimeException naming the command; the monitor carries on.
 *
 * @internal
 */
final readonly class IssuePoller
{
    /** Open issues fetched per poll; the repository has far fewer. */
    private const int ISSUE_LIMIT = 500;

    /** How a failure names the GitHub query. */
    private const string GH_ISSUE_LIST = 'gh issue list';

    /** How a failure names the daemon query. */
    private const string LIST_ELIGIBLE = 'issue-validity --list-eligible';

    public function __construct(
        private ProcessRunnerInterface $processes,
        private string $hooksDaemonCli,
        private string $projectRoot,
        private string $repository,
    ) {
    }

    /**
     * The open issues opened by an approved author.
     *
     * @return array<int, true> issue number => true
     */
    public function eligible(): array
    {
        $answer   = $this->json($this->hooksDaemonCli, 'issue-validity', '--list-eligible', '--json', '--project-root', $this->projectRoot);
        $eligible = \is_array($answer) ? ($answer['eligible'] ?? null) : null;
        if (!\is_array($eligible)) {
            throw $this->unexpected(self::LIST_ELIGIBLE);
        }

        $numbers = [];
        foreach ($eligible as $number) {
            if (!\is_int($number)) {
                throw $this->unexpected(self::LIST_ELIGIBLE);
            }

            $numbers[$number] = true;
        }

        return $numbers;
    }

    /**
     * Eligible issues assigned to nobody, outside the backlog and not yet
     * announced, lowest number first.
     *
     * @param array<int, true> $backlog   issues that existed when the monitor was set up
     * @param array<int, true> $announced issues this monitor run has already announced
     *
     * @return list<array{number: int, author: string, title: string}>
     */
    public function newIssues(array $backlog, array $announced): array
    {
        $eligible = $this->eligible();
        $open     = $this->json('gh', 'issue', 'list', '--repo', $this->repository, '--state', 'open', '--limit', (string)self::ISSUE_LIMIT, '--json', 'number,title,author,assignees');
        if (!\is_array($open)) {
            throw $this->unexpected(self::GH_ISSUE_LIST);
        }

        $new = [];
        foreach ($open as $issue) {
            if (!\is_array($issue) || !\is_int($issue['number'] ?? null)) {
                throw $this->unexpected(self::GH_ISSUE_LIST);
            }

            $number = $issue['number'];
            if (!isset($eligible[$number]) || isset($backlog[$number]) || isset($announced[$number]) || [] !== ($issue['assignees'] ?? [])) {
                continue;
            }

            $author = \is_array($issue['author'] ?? null) ? ($issue['author']['login'] ?? null) : null;
            $title  = $issue['title'] ?? null;
            $new[]  = ['number' => $number, 'author' => \is_string($author) ? $author : 'unknown', 'title' => \is_string($title) ? $title : ''];
        }

        usort($new, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return $new;
    }

    private function json(string $program, string ...$arguments): mixed
    {
        $result = $this->processes->run(new ProcessSpecDto([$program, ...array_values($arguments)], $this->projectRoot, timeout: 120.0, streamOutput: false));
        // The daemon CLI's absolute path adds nothing a reader needs.
        $shown = $program === $this->hooksDaemonCli ? implode(' ', $arguments) : $program . ' ' . implode(' ', $arguments);
        if (!$result->succeeded()) {
            throw new RuntimeException(\sprintf('%s failed (exit %d): %s', $shown, $result->exitCode, trim($result->output)));
        }

        try {
            return \Safe\json_decode($result->stdout, true);
        } catch (JsonException $jsonException) {
            throw new RuntimeException($shown . ' gave an unexpected answer: ' . $jsonException->getMessage(), 0, $jsonException);
        }
    }

    private function unexpected(string $command): RuntimeException
    {
        return new RuntimeException($command . ' gave an unexpected answer');
    }
}
