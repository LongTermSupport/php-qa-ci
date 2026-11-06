<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ShellCheck\Dto;

/**
 * Candidate shell files split into those the project owns and those a tool
 * installed into it owns.
 *
 * @internal
 */
final readonly class DaemonOwnedSplitDto
{
    /**
     * @param list<string> $kept    project-relative files to check
     * @param list<string> $skipped project-relative files owned by the Claude Code hooks daemon
     */
    public function __construct(
        public array $kept,
        public array $skipped,
    ) {
    }
}
