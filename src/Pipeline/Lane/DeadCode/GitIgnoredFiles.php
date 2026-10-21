<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DeadCode;

/**
 * The untracked files git ignores in a project, from one
 * `git ls-files -z --others --ignored --exclude-standard --directory` run in its root: each
 * ignored file, and each wholly ignored directory once, ending in `/`. A tracked file is never
 * listed, whatever the ignore rules say, since it is committed.
 *
 * @internal
 */
final readonly class GitIgnoredFiles
{
    /** The command, run in the project root, whose output fromListing() reads. */
    public const array COMMAND = ['git', '-c', 'core.quotePath=false', 'ls-files', '-z', '--others', '--ignored', '--exclude-standard', '--directory'];

    /** What ends a listed directory, and joins path segments. */
    private const string SEPARATOR = '/';

    /** @param list<string> $entries project-relative; a directory ends in `/` */
    private function __construct(private array $entries)
    {
    }

    public static function fromListing(string $listing): self
    {
        return new self(array_values(array_filter(explode("\0", $listing), static fn (string $entry): bool => '' !== $entry)));
    }

    /** Nothing ignored: what a project git cannot answer for gets. */
    public static function none(): self
    {
        return new self([]);
    }

    /** Whether the project-relative $relative is ignored, itself or as part of an ignored directory. */
    public function contains(string $relative): bool
    {
        return array_any(
            $this->entries,
            static fn (string $entry): bool => $entry === $relative
                || (str_ends_with($entry, self::SEPARATOR) && str_starts_with($relative . self::SEPARATOR, $entry)),
        );
    }
}
