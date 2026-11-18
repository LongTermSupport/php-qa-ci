<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Watches the git-tracked files across one read-only run. begin() records how the tree stands, and
 * each changedBy() says which tracked files moved since the step before, so a change is attributed
 * to the step that was running. A tree already dirty at the start is the baseline: a file in it is
 * blamed only when its content moves again.
 *
 * Idle in a writable run, where the fixers change files by design, and idle when git cannot say.
 *
 * @internal
 */
final class TrackedTreeWatch
{
    /** What a run that left a tracked file changed is reported as; the page is docs/tools/trackedFilesUnchanged.md. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.trackedFilesUnchanged';

    /** @var array<array-key, string>|null null while the watch is idle */
    private ?array $last = null;

    private ?ToolContext $context = null;

    public function __construct(
        private readonly ?TrackedTreeInterface $tree,
        private readonly OutputInterface $output,
    ) {
    }

    public function begin(ToolContext $context): void
    {
        $this->last    = null;
        $this->context = null;
        if (!$this->tree instanceof TrackedTreeInterface || !$context->config->readOnly) {
            return;
        }

        $state = $this->tree->dirtyFiles($context);
        if (null === $state) {
            $this->output->writeln('Tracked-file watch: could not read the tracked files (git gave no answer), so this run is not checked for changes to them.');

            return;
        }

        $this->last    = $state;
        $this->context = $context;
    }

    /**
     * @return list<string> the tracked files that changed since the previous call, sorted; empty when
     *                      nothing moved or the watch is idle
     */
    public function changedBy(): array
    {
        if (null === $this->last || !$this->context instanceof ToolContext || !$this->tree instanceof TrackedTreeInterface) {
            return [];
        }

        $now = $this->tree->dirtyFiles($this->context);
        if (null === $now) {
            return [];
        }

        $changed = [];
        foreach ($now as $path => $hash) {
            if (($this->last[$path] ?? null) !== $hash) {
                $changed[] = (string)$path;
            }
        }

        foreach (array_keys($this->last) as $path) {
            if (!\array_key_exists($path, $now)) {
                $changed[] = (string)$path;
            }
        }

        $this->last = $now;
        sort($changed);

        return $changed;
    }
}
