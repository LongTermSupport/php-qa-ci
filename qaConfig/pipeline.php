<?php

declare(strict_types=1);

/*
 * php-qa-ci's own pipeline extension: the dogfooding route from
 * docs/extending-the-pipeline.md, used on this repository first.
 *
 * shipmonk/dead-code-detector is under evaluation (Plan 00005, Phase 3). It is
 * kept out of the PHPStan extension installer (composer.json "extra") so the
 * gate stays as shipped, and runs only through this `-t`-only lane against
 * qaConfig/phpstan-dead-code.neon. No phase runs it until the evidence says so.
 */

use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
    ->withTool(
        new ToolDefinitionDto(
            name: 'deadCodeDetector',
            aliases: ['dcd', 'deadCode'],
            description: 'dead-code detection (under evaluation, -t only)',
            phase: null,
            supportsPaths: false,
        ),
        new class implements ToolInterface {
            public function name(): string
            {
                return 'deadCodeDetector';
            }

            public function identifier(): string
            {
                return 'project.deadCodeDetector';
            }

            public function run(ToolContext $context): ToolResultDto
            {
                $paths  = $context->config->paths;
                $result = $context->php->withoutXdebug(
                    $paths->pharDir . '/phpstan.phar',
                    ['analyse', '-c', $paths->projectConfigDir . '/phpstan-dead-code.neon', '--no-progress'],
                    $paths->projectRoot,
                );

                return ToolResultDto::fromExitCode($result->exitCode, 'dead-code detection');
            }
        },
    )
;
