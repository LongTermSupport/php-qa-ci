<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\BranchNamePolicy;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;

/**
 * The git probes the policy needs, each a quiet subprocess: whether the
 * project is a work tree, the current branch, and the repository's default
 * branch (origin/HEAD locally, else asked of the remote, which is what CI
 * checkouts need).
 *
 * @internal
 */
final readonly class GitBranches
{
    private const string HEAD = 'HEAD';

    public function __construct(private ProcessRunnerInterface $processes, private string $projectRoot)
    {
    }

    public function isWorkTree(): bool
    {
        if (is_dir($this->projectRoot . '/.git')) {
            return true;
        }

        return $this->git('rev-parse', '--is-inside-work-tree')->succeeded();
    }

    /** The current branch, or null when detached. */
    public function currentBranch(): ?string
    {
        $result = $this->git('rev-parse', '--abbrev-ref', self::HEAD);
        $branch = trim($result->output);
        if (!$result->succeeded() || '' === $branch || self::HEAD === $branch) {
            return null;
        }

        return $branch;
    }

    /** The repository's default branch, or null when neither probe can say. */
    public function defaultBranch(): ?string
    {
        $local = $this->git('symbolic-ref', 'refs/remotes/origin/HEAD');
        $ref   = trim($local->output);
        if ($local->succeeded() && str_starts_with($ref, 'refs/remotes/origin/')) {
            return substr($ref, \strlen('refs/remotes/origin/'));
        }

        $remote = $this->git('ls-remote', '--symref', 'origin', self::HEAD);
        if (!$remote->succeeded()) {
            return null;
        }

        foreach (explode("\n", $remote->output) as $line) {
            if (1 === \Safe\preg_match('/^ref:\s+(refs\/heads\/\S+)/', trim($line), $matches) && isset($matches[1])) {
                return substr($matches[1], \strlen('refs/heads/'));
            }
        }

        return null;
    }

    private function git(string ...$args): ProcessResultDto
    {
        return $this->processes->run(new ProcessSpecDto(['git', ...array_values($args)], $this->projectRoot, streamOutput: false));
    }
}
