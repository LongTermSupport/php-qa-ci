<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\DefectRecord\DefectRecordCheck;
use LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * The project record must be usable: every ignoreErrors entry in
 * qaConfig/phpstan.neon carries a comment naming the hazard accepted and its
 * scope, and qaConfig/defect-record.neon (the deferred Defects and no-pattern
 * conclusions method specification section 2 requires to be recorded) reads
 * in full. Both halves always run, so one failure does not hide the other.
 *
 * @internal
 */
final readonly class PhpstanIgnoreJustificationTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpstanIgnoreJustification';

    public function __construct(
        private IgnoreErrorsJustificationCheck $check = new IgnoreErrorsJustificationCheck(),
        private DefectRecordCheck $defectRecord = new DefectRecordCheck(),
    ) {
    }

    public function name(): string
    {
        return 'phpstanIgnoreJustification';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root         = $context->config->paths->projectRoot;
        $ignoreErrors = OutputCapture::run(fn (): int => $this->check->run($root), $context->output);
        $defectRecord = OutputCapture::run(fn (): int => $this->defectRecord->run($root), $context->output);
        if (0 === $ignoreErrors && 0 === $defectRecord) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(match (true) {
            0 === $defectRecord => 'an ignoreErrors entry has no usable justification',
            0 === $ignoreErrors => 'the defect record is malformed',
            default             => 'an ignoreErrors entry has no usable justification, and the defect record is malformed',
        });
    }
}
