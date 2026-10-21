<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\ShellCheckBinary;
use LTS\PHPQA\Pipeline\Lane\ShellCheck\CapturedPhpOutputScanner;
use LTS\PHPQA\Pipeline\Lane\ShellCheck\ShellFileFinder;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;

/**
 * Lints the project's shell scripts with the ShellCheck build php-qa-ci ships,
 * so `bin/qa` covers the Bash around a PHP project rather than leaving it to a
 * separate CI job. A green local pipeline that is still a red branch is the
 * defect this lane exists to remove.
 *
 * The binary is vendored and pinned: a ShellCheck whose version depends on
 * what the host has installed is not the same check, so a mismatch against the
 * pin is a crash rather than a quiet difference in verdict.
 *
 * The lane also reads the same scripts, and the run scripts of the tracked
 * GitHub Actions workflows, for a check ShellCheck has no rule for: a command
 * substitution that captures PHP's output with its warnings on stdout
 * ({@see CapturedPhpOutputScanner}).
 *
 * @internal
 */
final readonly class ShellCheckTool implements ToolInterface
{
    /** The lane's stable identifier, printed on failure; owned by ShellCheckBinary. */
    public const string IDENTIFIER = ShellCheckBinary::IDENTIFIER;

    /** ShellCheck exits 1 for findings; anything above that is its own failure, not the code's. */
    private const int EXIT_FINDINGS = 1;

    /** One line where a command substitution captures PHP with its warnings on stdout: the file, then the line. */
    private const string CAPTURED_FINDING = "%s:%d: PHP's output is captured with its warnings on stdout";

    /** What to do about those lines, printed once after them. */
    private const string CAPTURED_GUIDANCE = 'The PHP CLI displays errors on stdout unless an ini says otherwise, so a warning or deprecation becomes part of the captured value. '
        . 'Pass -d display_errors=stderr to php, before the script (docs/tools/shellCheck.md#php-output-captured-by-a-command-substitution).';

    /** The run's one-line summary when such a line was found. */
    private const string CAPTURED_SUMMARY = "a command substitution captures PHP's output with its warnings on stdout";

    public function name(): string
    {
        return 'shellCheck';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths    = $context->config->paths;
        $binary   = ShellCheckBinary::path($paths->libraryRoot);
        $pinned   = ShellCheckBinary::pinnedVersion($paths->libraryRoot);
        $unusable = ShellCheckBinary::ensureExecutable($binary);
        if (null !== $unusable || null === $pinned) {
            return $this->reportBrokenInstall($context, $unusable ?? 'no pinned version recorded', $pinned);
        }

        $tracked = $this->trackedFiles($context);
        if (null === $tracked) {
            return ToolResultDto::skipped('the project is not a git work tree, so there is no tracked file set to check');
        }

        $mismatch = $this->versionMismatch($context, $binary, $pinned);
        if ($mismatch instanceof ToolResultDto) {
            return $mismatch;
        }

        $globs  = $context->config->shellCheckGlobs;
        $finder = new ShellFileFinder($paths->projectRoot);
        $found  = $finder->find($tracked, $globs, ...$context->config->pathsToIgnore);
        if ([] === $found && [] !== $globs && null === $context->config->specifiedPath) {
            return $this->reportEmptyGlobs($context, ...$globs);
        }

        $files     = $this->narrowToSpecifiedPath($context, ...$found);
        $workflows = $this->narrowToSpecifiedPath($context, ...$finder->workflows($tracked, ...$context->config->pathsToIgnore));
        if ([] === $files && [] === $workflows) {
            $context->writeln('No shell files to check');

            return ToolResultDto::passed();
        }

        $checked = [] === $files ? ToolResultDto::passed() : $this->check($context, $binary, ...$files);
        if (ToolOutcomeEnum::Crashed === $checked->outcome) {
            return $checked;
        }

        $captured = $this->reportCapturedPhpOutput($context, new CapturedPhpOutputScanner(...$finder->phpScripts(...$tracked)), ...$files, ...$workflows);
        if ($checked->isSuccess() && !$captured) {
            return $checked;
        }

        $context->writeIdentifier(self::IDENTIFIER);

        $summaries = $checked->isSuccess() ? [] : [$checked->summary];
        if ($captured) {
            $summaries[] = self::CAPTURED_SUMMARY;
        }

        return ToolResultDto::failed(implode('; ', $summaries));
    }

    /**
     * Prints each line, in the shell files and the workflows, where a command substitution captures
     * PHP's output with its warnings on stdout; true when there is one.
     */
    private function reportCapturedPhpOutput(ToolContext $context, CapturedPhpOutputScanner $scanner, string ...$files): bool
    {
        $found = false;
        foreach ($files as $relative) {
            foreach ($scanner->scan(\Safe\file_get_contents($context->config->paths->projectRoot . '/' . $relative)) as $line) {
                $context->writeln(\sprintf(self::CAPTURED_FINDING, $relative, $line));
                $found = true;
            }
        }

        if ($found) {
            $context->writeln(self::CAPTURED_GUIDANCE);
        }

        return $found;
    }

    private function check(ToolContext $context, string $binary, string ...$files): ToolResultDto
    {
        $absolute = array_map(
            static fn (string $relative): string => $context->config->paths->projectRoot . '/' . $relative,
            array_values($files),
        );

        $result = $context->processes->run(new ProcessSpecDto(
            [$binary, '--severity=' . ShellCheckBinary::SEVERITY, '--', ...$absolute],
            $context->config->paths->projectRoot,
            streamOutput: false,
        ));

        if ($result->succeeded()) {
            $context->writeln(\sprintf('%d shell file(s) checked; no finding at severity %s or above', \count($absolute), ShellCheckBinary::SEVERITY));

            return ToolResultDto::passed();
        }

        $context->writeln($result->output);

        if (self::EXIT_FINDINGS !== $result->exitCode) {
            return ToolResultDto::crashed(\sprintf('ShellCheck crashed (exit %d)', $result->exitCode));
        }

        return ToolResultDto::failed('ShellCheck reported findings');
    }

    /**
     * The tracked, project-relative file list, or null when git cannot answer.
     *
     * @return list<string>|null
     */
    private function trackedFiles(ToolContext $context): ?array
    {
        $result = $context->processes->run(new ProcessSpecDto(
            ['git', 'ls-files', '--cached'],
            $context->config->paths->projectRoot,
            streamOutput: false,
        ));

        if (!$result->succeeded()) {
            return null;
        }

        return array_values(array_filter(
            array_map(trim(...), explode("\n", $result->output)),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    /** A crash when the committed binary is not the pinned release, otherwise null. */
    private function versionMismatch(ToolContext $context, string $binary, string $pinned): ?ToolResultDto
    {
        $result   = $this->probeVersion($context, $binary);
        $reported = ShellCheckBinary::reportedVersion($result->output);
        if ($result->succeeded() && $reported === $pinned) {
            return null;
        }

        $context->writeln(\sprintf(
            'The vendored ShellCheck reports %s but this checkout pins %s.',
            $reported ?? \sprintf('nothing readable (exit %d)', $result->exitCode),
            $pinned,
        ));
        $context->writeln('One pinned build has to run in every environment, or QA and CI are not the same check.');
        $context->writeln('Restore it with:');
        $context->writeln('  ' . ShellCheckBinary::fetchCommand($context->config->paths->libraryRoot, $pinned));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed('the vendored ShellCheck does not match the pinned release');
    }

    private function probeVersion(ToolContext $context, string $binary): ProcessResultDto
    {
        return $context->processes->run(new ProcessSpecDto(
            [$binary, '--version'],
            $context->config->paths->projectRoot,
            streamOutput: false,
        ));
    }

    private function reportBrokenInstall(ToolContext $context, string $reason, ?string $pinned): ToolResultDto
    {
        $context->writeln(\sprintf('The vendored ShellCheck is not usable: %s', $reason));
        $context->writeln(\sprintf(
            'Expected: %s (%s)',
            ShellCheckBinary::path($context->config->paths->libraryRoot),
            ShellCheckBinary::ARCHITECTURE,
        ));
        if (null !== $pinned) {
            $context->writeln('Restore it with:');
            $context->writeln('  ' . ShellCheckBinary::fetchCommand($context->config->paths->libraryRoot, $pinned));
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed('the vendored ShellCheck binary or its version pin is missing');
    }

    private function reportEmptyGlobs(ToolContext $context, string ...$globs): ToolResultDto
    {
        $context->writeln('withShellCheckGlobs() names files this repository does not track:');
        foreach ($globs as $glob) {
            $context->writeln('  ' . $glob);
        }

        $context->writeln('');
        $context->writeln('A hand-written glob list that matches nothing is a broken configuration, not a clean');
        $context->writeln('result: the list exists so those files are checked. Correct it, or drop it and let the');
        $context->writeln('lane discover shell files by extension and shebang.');
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed('every configured ShellCheck glob matched nothing');
    }

    /**
     * `-p` narrows the discovered set rather than replacing it: the shell
     * surface is still whatever the repository tracks, just the part under
     * the given path.
     *
     * @return list<string>
     */
    private function narrowToSpecifiedPath(ToolContext $context, string ...$found): array
    {
        $path = $context->config->specifiedPath;
        if (null === $path) {
            return array_values($found);
        }

        $prefix = rtrim($path, '/');

        return array_values(array_filter(
            $found,
            static fn (string $relative): bool => $relative === $prefix || str_starts_with($relative, $prefix . '/'),
        ));
    }
}
