<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;

/**
 * The git history the changelog release and the changelog lane read, each a
 * quiet argv subprocess in the project root. A command that must succeed and
 * does not is a ChangelogHistoryException, never an empty answer: an empty
 * answer from a broken history would read as "nothing changed".
 *
 * @api
 */
final readonly class ChangelogGit
{
    /** The commit trailer that states a change needs no changelog entry. */
    public const string TRAILER_KEY = 'Changelog';

    public function __construct(private ProcessRunnerInterface $processes, private string $projectRoot)
    {
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->lines($this->required('tag', '--list')->stdout, "\n");
    }

    public function isShallow(): bool
    {
        $result = $this->git('rev-parse', '--is-shallow-repository');

        return $result->succeeded() && 'true' === trim($result->stdout);
    }

    public function resolves(string $ref): bool
    {
        return $this->git('rev-parse', '--verify', '--quiet', $ref . '^{commit}')->succeeded();
    }

    public function mergeBase(string $ref): ?string
    {
        $result = $this->git('merge-base', 'HEAD', $ref);
        $sha    = trim($result->stdout);

        return $result->succeeded() && '' !== $sha ? $sha : null;
    }

    /**
     * Every path that differs between $base and the working tree, committed or
     * not, plus untracked files: what a run here would ship if it were committed.
     *
     * @return list<string>
     */
    public function changedFiles(string $base): array
    {
        $changed   = $this->lines($this->required('diff', '--name-only', '-z', '--no-renames', $base, '--')->stdout, "\0");
        $untracked = $this->lines($this->required('ls-files', '-z', '--others', '--exclude-standard')->stdout, "\0");

        return array_values(array_unique([...$changed, ...$untracked]));
    }

    /** A file's contents at a revision, or null when the file does not exist there. */
    public function fileAt(string $revision, string $path): ?string
    {
        $object = $revision . ':' . $path;
        if (!$this->git('cat-file', '-e', $object)->succeeded()) {
            return null;
        }

        return $this->required('show', $object)->stdout;
    }

    /**
     * The `Changelog:` trailer value of every commit in $base..HEAD that has one.
     *
     * @return list<string>
     */
    public function trailers(string $base): array
    {
        return $this->lines(
            $this->required('log', '--format=%(trailers:key=' . self::TRAILER_KEY . ',valueonly)', $base . '..HEAD')->stdout,
            "\n",
        );
    }

    /**
     * @param non-empty-string $separator
     *
     * @return list<string> the non-empty, trimmed parts
     */
    private function lines(string $output, string $separator): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode($separator, $output)),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    private function required(string ...$args): ProcessResultDto
    {
        $result = $this->git(...$args);
        if (!$result->succeeded()) {
            throw new ChangelogHistoryException(\sprintf(
                '`git %s` failed (exit %d): %s',
                implode(' ', $args),
                $result->exitCode,
                trim($result->output),
            ));
        }

        return $result;
    }

    private function git(string ...$args): ProcessResultDto
    {
        return $this->processes->run(new ProcessSpecDto(['git', ...array_values($args)], $this->projectRoot, streamOutput: false));
    }
}
