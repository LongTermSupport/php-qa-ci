<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Dto;

use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;

/**
 * The full result of ActiveRulesLister::list(): the resolved config path, the
 * PHPStan rule classes active in it, the always-on pipeline lanes, the
 * project record (ignoreErrors entries with justification), and the defect
 * record (deferred Defects and no-pattern conclusions).
 *
 * @internal
 */
final readonly class ActiveDefencesListingDto
{
    /**
     * @param list<ActiveRuleEntryDto>    $rules
     * @param list<PipelineLaneDto>       $pipelineLanes
     * @param list<ProjectRecordEntryDto> $projectRecord
     */
    public function __construct(
        public string $configPath,
        public array $rules,
        public array $pipelineLanes,
        public array $projectRecord,
        public DefectRecordDto $defectRecord = new DefectRecordDto(null, [], [], []),
    ) {
    }
}
