# Extending the pipeline

A project adds tools and phases to the shipped pipeline from `qaConfig/pipeline.php`, without
forking the registry. This is also the route for evaluating a candidate tool: run it through the
project's own pipeline against real code first, and propose bundling only once it has earned it.

## The contract

`qaConfig/pipeline.php` returns a closure over
[PipelineBuilder](../src/Pipeline/Tool/PipelineBuilder.php):

```php
return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
    ->withTool(new ToolDefinitionDto(/* ... */), new MyTool());
```

The builder arrives seeded with the shipped phases and tools (`PipelineBuilder::defaults()`), and
the run uses whatever the closure returns. It is read before the command line is parsed, so `-t`
can name anything registered here. The file is optional; a template ships at
`templates/qaConfig-pipeline.php`.

Three withers, all immutable:

| Wither                                       | Effect                                                                                                                                                               |
| -------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `withTool(ToolDefinitionDto, ToolInterface)` | Appends the tool to the end of its phase. A definition under an existing name replaces that tool in place. The definition's name and the tool's `name()` must agree. |
| `withPhase(PhaseDto, ?string $before)`       | Appends a phase, or inserts it before the named one. Every phase derives its own `-t all...` runner from the DTO.                                                    |
| `withPhaseOrder(string ...$names)`           | Sets the complete execution order; every phase exactly once.                                                                                                         |

A phase is named by a string; the shipped four are `PhaseEnum::CodingStandards->value`,
`PhaseEnum::Linting->value`, `PhaseEnum::StaticAnalysis->value` and `PhaseEnum::Testing->value`.
Naming a phase that does not exist throws `UnknownPhaseException` at load time, with the known
names in the message.

The types on this path are `@api`: `PipelineBuilder`, `PhaseDto`, `PhaseEnum`,
`ToolDefinitionDto`, `ToolGateEnum`, `UnknownPhaseException`, plus the lane contract already
documented in [upgrading-to-8.5.md](upgrading-to-8.5.md) section 5 (`ToolInterface`,
`ToolContext`, `ToolResultDto`, `PhpInvoker`).

## What a tool is

The same `ToolInterface` a `qaConfig/tools/<name>.php` override implements: `name()` is the
canonical registry name, `identifier()` the stable id printed on failure, `run(ToolContext)`
returns a `ToolResultDto`. A tool prints its own detail to the context and never exits the
process; the runner owns retries, aggregation and exit codes. Put the class under `qaConfig/`
(autoloaded as `QaConfig\` in the shipped templates) so it stays out of `src/`.

The definition decides how the run treats it:

- `phase` places it; `null` makes it a `-t`-only pseudo-tool the phases never run.
- `supportsPaths` allows `-p <path>` with it.
- `gate` is `ToolGateEnum::None` (always), `NotQuick` (skipped under `phpqaQuickTests=1`) or
  `Infection` (also needs coverage and `useInfection`).
- `banner` is printed before it runs as part of a phase.

## Worked example: a security audit phase

The goal: `composer audit` as its own phase between static analysis and testing, selectable as
`-t sa` or `-t allSec`.

`qaConfig/Pipeline/SecurityAuditTool.php`:

```php
<?php

declare(strict_types=1);

namespace QaConfig\Pipeline;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

final readonly class SecurityAuditTool implements ToolInterface
{
    public function name(): string
    {
        return 'securityAudit';
    }

    public function identifier(): string
    {
        return 'project.securityAudit';
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $result = $context->processes->run(new ProcessSpecDto(
            ['composer', 'audit', '--no-interaction'],
            $context->config->paths->projectRoot,
        ));

        return ToolResultDto::fromExitCode($result->exitCode, 'composer audit');
    }
}
```

`qaConfig/pipeline.php`:

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use QaConfig\Pipeline\SecurityAuditTool;

return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
    ->withPhase(
        new PhaseDto('security', 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools'),
        before: PhaseEnum::Testing->value,
    )
    ->withTool(
        new ToolDefinitionDto('securityAudit', ['sa', 'securityAudit'], 'dependency vulnerability audit', 'security', false, banner: 'Auditing Dependencies'),
        new SecurityAuditTool(),
    )
;
```

Then:

```bash
vendor/bin/qa -h            # allSec and sa|securityAudit appear in the usage text
vendor/bin/qa -t sa         # runs the tool alone
vendor/bin/qa -t allSec     # runs the phase
vendor/bin/qa               # the full pipeline, security after static analysis
```

## The archetype: dogfood before bundling

A tool earns a place in the shipped pipeline by evidence, not opinion. The process:

1. Install the candidate in the project (`composer require --dev`), write the lane under
   `qaConfig/`, and register it from `pipeline.php` as above.
2. Run it against real code for long enough to know its false-positive rate and what it costs
   in wall-clock time. Record both in the project's plan journal.
3. Fix what it finds that is real, or decide it is not worth the noise. Either is a result.
4. Only then propose bundling: a lane under `src/Pipeline/Lane/`, a row in
   `ToolRegistry::shipped()`, an opt-in switch on `QaConfigBuilder` if the tool is not for
   everyone, and a docs page under `docs/tools/`.

The project-level lane is not throwaway work: the bundled lane is the same class moved home.
