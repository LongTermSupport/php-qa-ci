<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent\Dto;

/**
 * PHPArkitect's report, reduced to what agent mode records.
 *
 * Keyed by fully-qualified class name rather than by file, because that is
 * all PHPArkitect gives: it matches rules against parsed classes and reports
 * neither a path nor a line. Resolving a class back to its file is a separate
 * step, done against the class set the run analysed.
 *
 * There is deliberately no class count. Agent mode reports FILES, and the two
 * numbers differ: several classes can share a file, and several unresolvable
 * ones collapse into a single per-rule report.
 *
 * @internal
 */
final readonly class ParsedArchReportDto
{
    /** @param array<string, list<FileErrorDto>> $classes keyed by FQCN, in the analyser's order */
    public function __construct(public array $classes)
    {
    }

    public function violationCount(): int
    {
        $count = 0;
        foreach ($this->classes as $violations) {
            $count += \count($violations);
        }

        return $count;
    }
}
