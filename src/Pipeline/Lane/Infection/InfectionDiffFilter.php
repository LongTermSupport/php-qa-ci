<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use Closure;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffFilterDto;
use Safe\Exceptions\FilesystemException;

/**
 * Parses the changed-file list of a diff-mode run into the positional paths
 * Infection mutates.
 *
 * Input is the output of
 *   git diff <base>...HEAD -z -M --name-status --diff-filter=AMRCD --relative -- <srcDir> <testsDir> <fullRunTriggers>
 * (see gitDiffArguments()), to which the lane may append uncommitted work in
 * the same record form (nameStatusFromGitStatus()). The three-dot diff lists
 * only files changed on this branch since the merge base, so the scope is not
 * widened by a base ref that has advanced. `-z` keeps unusual file names
 * verbatim; `-M` reports a moved file as a rename, whose new path is mutated.
 *
 * - A changed full-run trigger (InfectionFullRunTriggers: the Infection and
 *   PHPUnit configs and the test bootstrap) is listed as such, even under the
 *   tests directory. Any other file outside the source and tests directories
 *   is left out quietly: it decides neither what is mutated nor whether a
 *   mutant is killed.
 * - A modified or renamed PHP file whose change is only comments, docblocks
 *   or whitespace (CommentOnlyChange, against its base version read through
 *   `$baseContent`) is listed as comment-only and brings nothing in. In a
 *   file with a comment carrying an Infection or coverage directive, any
 *   comment change counts; an added or copied file, or an unreadable one,
 *   always counts.
 * - A source file added, modified, renamed or copied is mutated; a deleted
 *   one has nothing left to mutate.
 * - A test file changed in any way (deleted included) mutates the source it
 *   is named after (TestSourceMirror), so a weakened test is checked against
 *   the code it pins. A test-directory file that mirrors no source is listed
 *   as unmapped for the lane to report.
 *
 * Only PHP files outside the project's ignored paths are mutated; each is
 * absolutised against the working directory, because Infection resolves a
 * relative positional path against the infection.json directory rather than
 * the CWD.
 *
 * @internal
 */
final readonly class InfectionDiffFilter
{
    public function __construct(
        private CommentOnlyChange $commentOnly = new CommentOnlyChange(),
    ) {
    }

    /** @return list<string> the argv (after `git`) that produces the output this class parses */
    public function gitDiffArguments(string $diffBase, string $srcDir, string $testsDir, string ...$fullRunTriggers): array
    {
        return ['--no-pager', 'diff', $diffBase . '...HEAD', '-z', '-M', '--name-status', '--diff-filter=AMRCD', '--relative', '--', $srcDir, $testsDir, ...array_values($fullRunTriggers)];
    }

    /**
     * `git status --porcelain=v1 -z` output as the `-z --name-status` records
     * fromGitDiffOutput() reads, so uncommitted work is scoped by the same
     * rules: untracked is added, a rename or copy keeps both paths, and a
     * path the working-tree column reports deleted (AD, MD, RD) is followed
     * by a deletion, since only what is on disk can be mutated. Status
     * paths are relative to the repository root; `$prefix` (`git rev-parse
     * --show-prefix`) is stripped so they match the project-relative diff.
     */
    public function nameStatusFromGitStatus(string $gitStatusOutput, string $prefix = ''): string
    {
        $gitStatusOutput = $this->withoutPrefix($gitStatusOutput, $prefix);
        $tokens          = explode("\0", $gitStatusOutput);
        $count           = \count($tokens);
        $records         = '';
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (\strlen($token) < 4) {
                continue;
            }

            $codes = substr($token, 0, 2);
            $path  = substr($token, 3);
            $code  = '??' === $codes ? 'A' : substr(ltrim($codes), 0, 1);
            if (('R' === $code || 'C' === $code) && $index + 1 < $count) {
                ++$index;
                $records .= $code . "\0" . $tokens[$index] . "\0" . $path . "\0";
            } else {
                $kept     = 'D' === $code || 'A' === $code;
                $records .= ($kept ? $code : 'M') . "\0" . $path . "\0";
            }

            if ('D' === $codes[1] && 'D' !== $code) {
                $records .= "D\0" . $path . "\0";
            }
        }

        return $records;
    }

    /**
     * @param Closure(string): ?string|null $baseContent a file's content at the diff base, by project-relative
     *                                                   path, null when it cannot be read; without a reader no
     *                                                   change is treated as comment-only
     */
    public function fromGitDiffOutput(string $gitDiffOutput, string $cwd, string $srcDir, string $testsDir, IgnoredPaths $ignored, ?Closure $baseContent = null, string ...$fullRunTriggers): InfectionDiffFilterDto
    {
        $mirror      = new TestSourceMirror($cwd, $srcDir, $testsDir);
        $srcPrefix   = $this->relative($srcDir, $cwd) . '/';
        $testsPrefix = $this->relative($testsDir, $cwd) . '/';
        $triggers    = array_map(fn (string $trigger): string => $this->relative($trigger, $cwd), $fullRunTriggers);

        $changed     = [];
        $mirrored    = [];
        $unmapped    = [];
        $config      = [];
        $commentOnly = [];
        foreach ($this->entries($gitDiffOutput) as [$status, $path, $previous]) {
            if ('R' === $status && null !== $previous) {
                unset($changed[$previous], $mirrored[$previous]);
            }

            if (\in_array($path, $triggers, true)) {
                $config[] = $path;

                continue;
            }

            if (str_starts_with($path, $srcPrefix)) {
                if ('D' === $status) {
                    unset($changed[$path], $mirrored[$path]);
                } elseif (str_ends_with($path, '.php') && !$ignored->contains($cwd . '/' . $path)) {
                    if ($this->isCommentOnly($status, $path, $previous, $cwd, $baseContent)) {
                        $commentOnly[$path] = true;
                    } else {
                        $changed[$path] = true;
                    }
                }

                continue;
            }

            if (!str_starts_with($path, $testsPrefix)) {
                continue;
            }

            if ($this->isCommentOnly($status, $path, $previous, $cwd, $baseContent)) {
                $commentOnly[$path] = true;

                continue;
            }

            $source = $mirror->sourceFor($path) ?? (null === $previous ? null : $mirror->sourceFor($previous));
            if (null === $source) {
                $unmapped[] = $path;
            } elseif (!isset($changed[$source]) && !$ignored->contains($cwd . '/' . $source)) {
                $mirrored[$source] ??= $path;
            }
        }

        $mirrored = array_diff_key($mirrored, $changed);
        $relative = [...array_keys($changed), ...array_keys($mirrored)];

        return new InfectionDiffFilterDto(
            array_map(static fn (string $file): string => $cwd . '/' . $file, $relative),
            $relative,
            $mirrored,
            $unmapped,
            $config,
            array_keys(array_diff_key($commentOnly, $changed)),
        );
    }

    /** `git status --porcelain=v1 -z` output with every path made relative to `$prefix` */
    public function withoutPrefix(string $gitStatusOutput, string $prefix): string
    {
        if ('' === $prefix) {
            return $gitStatusOutput;
        }

        $tokens = explode("\0", $gitStatusOutput);
        foreach ($tokens as $index => $token) {
            $at = str_starts_with($token, $prefix) ? 0 : 3;
            if (substr($token, $at, \strlen($prefix)) === $prefix) {
                $tokens[$index] = substr($token, 0, $at) . substr($token, $at + \strlen($prefix));
            }
        }

        return implode("\0", $tokens);
    }

    /**
     * A modified or renamed PHP file whose tokens, comments and whitespace
     * aside, match its base version (a rename's old path). An added or copied
     * file is new code and is never compared; one either side of which cannot
     * be read counts as a change, so doubt always means mutating.
     *
     * @param Closure(string): ?string|null $baseContent
     */
    private function isCommentOnly(string $status, string $path, ?string $previous, string $cwd, ?Closure $baseContent): bool
    {
        if (!$baseContent instanceof Closure || !\in_array($status, ['M', 'R'], true) || !str_ends_with($path, '.php')) {
            return false;
        }

        $working = $this->workingContent($cwd . '/' . $path);
        if (!\is_string($working)) {
            return false;
        }

        $base = $baseContent($previous ?? $path);

        return null !== $base && $this->commentOnly->isCommentOnly($base, $working);
    }

    /** The file's content, or why it could not be read (absent, not a regular file, unreadable) */
    private function workingContent(string $file): string|FilesystemException
    {
        if (!is_file($file) || !is_readable($file)) {
            return new FilesystemException($file . ' is not a readable file');
        }

        try {
            return \Safe\file_get_contents($file);
        } catch (FilesystemException $filesystemException) {
            return $filesystemException;
        }
    }

    /**
     * The `-z --name-status` records: a status token, then one path, or two
     * (old, new) for a rename or copy.
     *
     * @return list<array{string, string, ?string}> status letter, path, previous path of a rename/copy
     */
    private function entries(string $gitDiffOutput): array
    {
        $tokens  = explode("\0", $gitDiffOutput);
        $entries = [];
        $count   = \count($tokens);
        $index   = 0;
        while ($index < $count) {
            $status    = substr(trim($tokens[$index]), 0, 1);
            $paired    = 'R' === $status || 'C' === $status;
            $pathIndex = $index + ($paired ? 2 : 1);
            if ('' !== $status && $pathIndex < $count && '' !== $tokens[$pathIndex]) {
                $entries[] = [$status, $tokens[$pathIndex], $paired ? $tokens[$index + 1] : null];
            }

            $index += $paired ? 3 : 2;
        }

        return $entries;
    }

    private function relative(string $dir, string $cwd): string
    {
        $root = rtrim($cwd, '/') . '/';

        return trim(str_starts_with($dir, $root) ? substr($dir, \strlen($root)) : $dir, '/');
    }
}
