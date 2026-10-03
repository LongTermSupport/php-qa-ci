<?php

declare(strict_types=1);

namespace LTS\PHPQA\DefectRecord\Dto;

/**
 * A project's defect record as read: where it is (null when the project has
 * not written one), its well-formed entries, and every problem that kept an
 * entry out. A record with problems is not a usable record; the problems are
 * carried so the caller can name each one.
 *
 * @internal
 */
final readonly class DefectRecordDto
{
    /**
     * @param list<DeferredDefectDto>      $deferred
     * @param list<NoPatternConclusionDto> $noPattern
     * @param list<string>                 $problems
     */
    public function __construct(
        public ?string $path,
        public array $deferred,
        public array $noPattern,
        public array $problems,
    ) {
    }
}
