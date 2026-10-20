<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

/**
 * A tool that reads the registry of the run it belongs to. PipelineBuilder::build()
 * hands each one the registry it builds, so the tool sees the tokens the
 * project's qaConfig/pipeline.php added without that file being required a
 * second time, which would die on any function, class or constant it declares.
 *
 * @internal
 */
interface RegistryAwareToolInterface extends ToolInterface
{
    /** The same tool, reading $registry. */
    public function withRegistry(ToolRegistry $registry): self;
}
