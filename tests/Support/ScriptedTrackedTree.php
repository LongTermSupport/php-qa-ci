<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Runner\TrackedTreeInterface;
use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * A tracked tree a test dirties by hand: a tool calls touch() as a lane that wrote a tracked file
 * would, and counts how often the run asked.
 */
final class ScriptedTrackedTree implements TrackedTreeInterface
{
    public int $asked = 0;

    /** @var array<array-key, string> */
    private array $dirty = [];

    public function __construct(private readonly bool $answers = true)
    {
    }

    public function touch(string $path, string $content = 'changed'): void
    {
        $this->dirty[$path] = $content;
    }

    public function clean(string $path): void
    {
        unset($this->dirty[$path]);
    }

    public function dirtyFiles(ToolContext $context): ?array
    {
        ++$this->asked;

        return $this->answers ? $this->dirty : null;
    }
}
