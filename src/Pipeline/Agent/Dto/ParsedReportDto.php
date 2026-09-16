<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent\Dto;

/**
 * An analyser's structured report, reduced to what agent mode records: the
 * findings grouped by the file they belong to, and the ones that belong to no
 * file at all.
 *
 * The two lists are kept apart because a global error (a worker dying, an
 * unmatched ignore pattern) cannot be written into any per-file report
 * without attributing it to a file that did not cause it.
 *
 * @internal
 */
final readonly class ParsedReportDto
{
    /**
     * @param array<string, list<FileErrorDto>> $files        keyed by absolute file path, in the analyser's order
     * @param list<string>                      $globalErrors messages belonging to the run rather than to a file
     */
    public function __construct(
        public array $files,
        public array $globalErrors,
    ) {
    }

    public function errorCount(): int
    {
        $count = \count($this->globalErrors);
        foreach ($this->files as $errors) {
            $count += \count($errors);
        }

        return $count;
    }

    public function fileCount(): int
    {
        return \count($this->files);
    }
}
