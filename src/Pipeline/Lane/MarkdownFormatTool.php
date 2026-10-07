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
    /** The lane's stable identifier, printed on failure and resolved by rule-doc. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.markdownFormat';

    /** The daemon prints this for each file a `--check` run would rewrite. */
    private const string PENDING_MARKER = 'Would reformat:';

    /**
     * git's message outside a work tree, in the C locale the probe runs in,
     * so a translated git prints it too. There is no ignore list there, and
     * the daemon refuses nothing either, so it means nothing is ignored.
     */
    private const string NOT_A_REPOSITORY = 'not a git repository';

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

        $paths = [];
        foreach ($context->config->markdownFormatPaths as $path) {
            if (file_exists($root . '/' . $path)) {
                $paths[] = $path;

                continue;
            }

            $context->writeln('Not present, skipped: ' . $path);
        }

        if ([] !== $paths) {
            $probe      = $this->gitIgnored($context, ...$paths);
            $outsideGit = !$probe->succeeded() && str_contains($probe->output, self::NOT_A_REPOSITORY);
            if (!$outsideGit && !\in_array($probe->exitCode, [0, 1], true)) {
                $context->writeln(rtrim($probe->output));
                $context->writeIdentifier(self::IDENTIFIER);

                return ToolResultDto::crashed(\sprintf('git check-ignore could not run (exit %d)', $probe->exitCode));
            }

            $ignored = $outsideGit ? [] : array_map(trim(...), explode("\n", $probe->stdout));
            foreach (array_intersect($paths, $ignored) as $path) {
                $context->writeln('Gitignored, skipped: ' . $path);
            }

            $paths = array_values(array_diff($paths, $ignored));
        }

        if ([] === $paths) {
            return ToolResultDto::skipped('no markdown path to format: each is absent or gitignored');
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

    /**
     * The daemon refuses a gitignored path it is handed by name, so ignored
     * paths are found first and dropped. Exit 0 lists the ignored ones, exit 1
     * means none is; anything else is git failing to answer. Paths are
     * printed unquoted, so a non-ASCII one matches the configured spelling.
     */
    private function gitIgnored(ToolContext $context, string ...$paths): ProcessResultDto
    {
        return $context->processes->run(new ProcessSpecDto(
            ['git', '-c', 'core.quotePath=false', 'check-ignore', '--', ...array_values($paths)],
            $context->config->paths->projectRoot,
            env: ['LC_ALL' => 'C'],
            streamOutput: false,
        ));
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
