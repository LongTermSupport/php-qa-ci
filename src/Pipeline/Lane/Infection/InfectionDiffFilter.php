<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffFilterDto;

/**
 * Parses the changed-file list of a diff-mode run into the positional paths
 * Infection mutates.
 *
 * Input is the output of
 *   git diff <base>...HEAD -z -M --name-status --diff-filter=AMRCD --relative -- <srcDir> <testsDir>
 * (see gitDiffArguments()). The three-dot diff lists only files changed on
 * this branch since the merge base, read from committed history alone, so
 * the scoping can neither be skewed by uncommitted work nor widened by a base
 * ref that has advanced. `-z` keeps unusual file names verbatim; `-M` reports
 * a moved file as a rename, whose new path is mutated.
 *
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
    /** @return list<string> the argv (after `git`) that produces the output this class parses */
    public function gitDiffArguments(string $diffBase, string $srcDir, string $testsDir): array
    {
        return ['--no-pager', 'diff', $diffBase . '...HEAD', '-z', '-M', '--name-status', '--diff-filter=AMRCD', '--relative', '--', $srcDir, $testsDir];
    }

    public function fromGitDiffOutput(string $gitDiffOutput, string $cwd, string $srcDir, string $testsDir, IgnoredPaths $ignored): InfectionDiffFilterDto
    {
        $mirror    = new TestSourceMirror($cwd, $srcDir, $testsDir);
        $srcPrefix = $this->relative($srcDir, $cwd) . '/';

        $changed  = [];
        $mirrored = [];
        $unmapped = [];
        foreach ($this->entries($gitDiffOutput) as [$status, $path, $previous]) {
            if (str_starts_with($path, $srcPrefix)) {
                if ('D' !== $status && str_ends_with($path, '.php') && !$ignored->contains($cwd . '/' . $path)) {
                    $changed[$path] = true;
                }

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
        );
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
