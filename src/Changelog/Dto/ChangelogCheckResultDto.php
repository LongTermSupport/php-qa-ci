<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog\Dto;

/**
 * What the changelog check found: `$report` is what it established, one line
 * each, and `$problems` what fails it, each possibly several lines long.
 *
 * @api
 */
final readonly class ChangelogCheckResultDto
{
    /**
     * @param list<string> $report
     * @param list<string> $problems
     */
    public function __construct(
        public array $report,
        public array $problems,
    ) {
    }

    public function passed(): bool
    {
        return [] === $this->problems;
    }
}
