<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Applies the project's qaConfig/pipeline.php to the pipeline builder. The
 * file returns a closure `static fn (PipelineBuilder $pipeline): PipelineBuilder`
 * that adds phases and tools to the shipped set; anything else is an error.
 *
 * A sibling of qa.php rather than a second return from it: qa.php's contract
 * is one closure over one builder, and the two builders answer different
 * questions (how the run is configured; what the run executes).
 *
 * @internal
 */
final readonly class PipelineConfigLoader
{
    public const string FILE = 'pipeline.php';

    public function __construct(private OutputInterface $output)
    {
    }

    public function apply(PipelineBuilder $builder, string $projectConfigDir): PipelineBuilder
    {
        $file = $projectConfigDir . '/' . self::FILE;
        if (!is_file($file)) {
            return $builder;
        }

        $this->output->writeln('');
        $this->output->writeln('Found project pipeline at ' . $file);

        $closure = require $file;
        if (!\is_callable($closure)) {
            throw new RuntimeException(\sprintf('%s must return a closure taking and returning a PipelineBuilder, got %s', $file, get_debug_type($closure)));
        }

        $extended = $closure($builder);
        if (!$extended instanceof PipelineBuilder) {
            throw new RuntimeException(\sprintf('%s must return the extended PipelineBuilder, got %s', $file, get_debug_type($extended)));
        }

        $this->output->writeln('Project pipeline applied');

        return $extended;
    }
}
