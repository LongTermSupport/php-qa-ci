<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffFilterDto;

/**
 * Parses the changed-file list of a diff-mode run into the positional paths
 * Infection mutates.
 *
 * Input is the output of
 *   git diff <base>...HEAD --diff-filter=AM --name-only --relative -- <srcDir>
 * (see gitDiffArguments()). The
 * three-dot diff lists only files changed on this branch since the merge
 * base, read from committed history alone, so the scoping can neither be
 * skewed by uncommitted work nor widened by a base ref that has advanced.
 * Only PHP files survive; each is absolutised against the working directory,
 * because Infection resolves a relative positional path against the
 * infection.json directory rather than the CWD.
 *
 * @internal
 */
final readonly class InfectionDiffFilter
{
    /** @return list<string> the argv (after `git`) that produces the output this class parses */
    public function gitDiffArguments(string $diffBase, string $srcDir): array
    {
        return ['--no-pager', 'diff', $diffBase . '...HEAD', '--diff-filter=AM', '--name-only', '--relative', '--', $srcDir];
    }

    public function fromGitDiffOutput(string $gitDiffOutput, string $cwd): InfectionDiffFilterDto
    {
        $relative   = [];
        $positional = [];
        foreach (explode("\n", $gitDiffOutput) as $line) {
            $file = rtrim($line, "\r");
            if ('' === $file || !str_ends_with($file, '.php')) {
                continue;
            }

            $relative[]   = $file;
            $positional[] = $cwd . '/' . $file;
        }

        return new InfectionDiffFilterDto($positional, $relative);
    }
}
