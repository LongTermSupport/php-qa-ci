<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\HooksDaemon\HooksDaemonCliLocator;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Formats the project's markdown with the hooks daemon's own formatter, so
 * the files are already in the form the daemon rewrites them to after an edit
 * and on every restart, and the two never take turns rewriting them. It
 * formats nothing itself: the daemon's code is the only way to agree with the
 * daemon byte for byte, at whatever mdformat version its venv resolved.
 *
 * Without the daemon there is nothing to agree with, so the lane skips and
 * says so; CI has no daemon. A read-only run checks every path and fails on
 * the files the daemon would rewrite; a writable run rewrites them.
 *
 * @internal
 */
final readonly class MarkdownFormatTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.markdownFormat';

    /** The daemon prints this for each file a `--check` run would rewrite. */
    private const string PENDING_MARKER = 'Would reformat:';

    /** The daemon venv starts cold; a hung daemon must not hang the pipeline. */
    private const float TIMEOUT_SECONDS = 600.0;

    public function __construct(private HooksDaemonCliLocator $locator = new HooksDaemonCliLocator())
    {
    }

    public function name(): string
    {
        return 'markdownFormat';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root = $context->config->paths->projectRoot;
        $cli  = $this->locator->locate($root);
        if (null === $cli) {
            $context->writeln(\sprintf('No hooks daemon at %s in this project or above it in its work tree, nothing to agree with', HooksDaemonCliLocator::CLI));

            return ToolResultDto::skipped('the hooks daemon is not installed, and its formatter is the one this lane runs');
        }

        $paths = array_values(array_filter(
            $context->config->markdownFormatPaths,
            static fn (string $path): bool => file_exists($root . '/' . $path),
        ));
        if ([] === $paths) {
            $context->writeln('None of the markdown paths exists: ' . implode(', ', $context->config->markdownFormatPaths));

            return ToolResultDto::skipped('none of the markdown paths exists');
        }

        $readOnly = $context->config->readOnly;
        $pending  = false;
        foreach ($paths as $path) {
            $result = $this->format($context, $cli, $path, $readOnly);
            $output = rtrim($result->output);
            if ('' !== $output) {
                $context->writeln($output);
            }

            if ($result->succeeded()) {
                continue;
            }

            if (!$readOnly || !str_contains($result->output, self::PENDING_MARKER)) {
                $context->writeIdentifier(self::IDENTIFIER);

                return ToolResultDto::crashed(\sprintf('the hooks daemon formatter could not run (exit %d)', $result->exitCode));
            }

            $pending = true;
        }

        if (!$pending) {
            return ToolResultDto::passed();
        }

        ReadOnlyGuidance::wouldModify($context, 'Markdown Format', 'mdf');
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('markdown the hooks daemon would reformat, in a read-only run');
    }

    private function format(ToolContext $context, string $cli, string $path, bool $readOnly): ProcessResultDto
    {
        $command = $readOnly
            ? [$cli, 'format-markdown', '--check', $path]
            : [$cli, 'format-markdown', $path];

        return $context->processes->run(new ProcessSpecDto(
            $command,
            $context->config->paths->projectRoot,
            timeout: self::TIMEOUT_SECONDS,
            streamOutput: false,
        ));
    }
}
