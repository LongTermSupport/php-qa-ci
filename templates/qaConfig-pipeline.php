<?php

declare(strict_types=1);

/**
 * Project pipeline extension — TEMPLATE.
 *
 *   cp vendor/lts/php-qa-ci/templates/qaConfig-pipeline.php qaConfig/pipeline.php
 *
 * The pipeline seeds a PipelineBuilder with the shipped phases and tools,
 * hands it to this closure before the command line is parsed, and runs with
 * what the closure returns. A tool added here is selectable with `-t` and
 * runs in its phase like a shipped one; a phase added here gets its own
 * `-t all...` runner. See docs/extending-the-pipeline.md for the process.
 *
 * You do not need this file for the shipped pipeline: delete it if you add
 * nothing.
 */

use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use LTS\PHPQA\Pipeline\Tool\ToolGateEnum;

return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
    // A phase of your own, inserted before testing. Drop `before:` to append.
    ->withPhase(
        new PhaseDto(
            name: 'security',
            banner: 'Running All Security Tools',
            runnerName: 'allSecurityTools',
            runnerAliases: ['allSec'],
            runnerDescription: 'all security tools',
        ),
        before: PhaseEnum::Testing->value,
    )
    // A tool of your own: its definition (name, -t aliases, description, phase,
    // whether it accepts -p, gate, banner) and the ToolInterface that runs it.
    ->withTool(
        new ToolDefinitionDto(
            name: 'securityAudit',
            aliases: ['sa', 'securityAudit'],
            description: 'dependency vulnerability audit',
            phase: 'security',
            supportsPaths: false,
            gate: ToolGateEnum::None,
            banner: 'Auditing Dependencies For Known Vulnerabilities',
        ),
        new \QaConfig\Pipeline\SecurityAuditTool(),
    )
;
