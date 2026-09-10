<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Config\Exception\LegacyBashConfigException;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use RuntimeException;

/**
 * The project's hookPre.php and hookPost.php: each returns a callable that
 * receives the ToolContext. The pre hook runs after configuration and before
 * the lock; the post hook runs only after every tool passed.
 *
 * The Bash forms (hookPre.bash / hookPost.bash) are refused with migration
 * guidance rather than silently ignored.
 *
 * @internal
 */
final readonly class HookRunner
{
    public function runPre(ToolContext $context): void
    {
        $this->run('hookPre', $context);
    }

    public function runPost(ToolContext $context): void
    {
        $this->run('hookPost', $context);
    }

    private function run(string $hook, ToolContext $context): void
    {
        $dir = $context->config->paths->projectConfigDir;
        if (is_file($dir . '/' . $hook . '.bash')) {
            throw LegacyBashConfigException::hook($hook, $dir);
        }

        $file = $dir . '/' . $hook . '.php';
        if (!is_file($file)) {
            return;
        }

        $context->writeln('');
        $context->writeln(\sprintf('Running %s hook %s', 'hookPre' === $hook ? 'pre' : 'post', $file));
        $context->writeln('------------------------------------------------');

        $callable = require $file;
        if (!\is_callable($callable)) {
            throw new RuntimeException(\sprintf('%s must return a callable that accepts a ToolContext, got %s', $file, get_debug_type($callable)));
        }

        $callable($context);
    }
}
