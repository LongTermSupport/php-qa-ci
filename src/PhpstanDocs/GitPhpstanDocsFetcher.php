<?php

declare(strict_types=1);

namespace LTS\PHPQA\PhpstanDocs;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Fetches the pages with a shallow, blob-less, sparse clone of phpstan/phpstan:
 * one round trip for the eleven hundred pages, and none of the repository's
 * committed phar. The release tarball cannot serve: it export-ignores
 * `website/`. The tip of the default branch is taken rather than the phar's
 * tag, because the first-party extensions release on their own schedule and
 * their newest identifiers are documented there before any PHPStan tag.
 *
 * @internal
 */
final readonly class GitPhpstanDocsFetcher implements PhpstanDocsFetcherInterface
{
    /** phpstan/phpstan, where phpstan.org's pages are written. */
    public const string REPOSITORY = 'https://github.com/phpstan/phpstan';

    /** What the sparse checkout keeps, as git's non-cone patterns. */
    private const array SPARSE_PATHS = ['/website/errors/', '/website/src/errorsIdentifiers.json', '/LICENSE'];

    /** A shallow clone over a slow link, with room to spare. */
    private const int TIMEOUT_SECONDS = 300;

    public function __construct(private string $repository = self::REPOSITORY)
    {
    }

    public function fetch(string $into): string
    {
        $this->git('clone', '--quiet', '--depth', '1', '--filter=blob:none', '--sparse', $this->repository, $into);
        $this->git('-C', $into, 'sparse-checkout', 'set', '--no-cone', ...self::SPARSE_PATHS);

        return $this->git('-C', $into, 'rev-parse', '--abbrev-ref', 'HEAD')
            . '@' . $this->git('-C', $into, 'rev-parse', '--short=12', 'HEAD');
    }

    /** @return string the command's output, trimmed */
    private function git(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], null, null, null, self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $processFailedException) {
            throw new RuntimeException(\sprintf(
                'could not fetch the PHPStan identifier pages from %s: %s',
                $this->repository,
                trim($process->getErrorOutput()),
            ), 0, $processFailedException);
        }

        return trim($process->getOutput());
    }
}
