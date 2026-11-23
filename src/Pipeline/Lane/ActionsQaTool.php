<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Config\ActionsQaBinary;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Checks the project's GitHub Actions workflows with actions-qa-ci, which runs actionlint,
 * ShellCheck over the run: scripts and zizmor behind stable identifiers of its own, plus the
 * project-wide rules (lint coverage, push concurrency, schedule budget) its project record
 * `.github/actions-qa-ci.yml` configures. The question it answers is "is this workflow correct
 * and safe", which the shellCheck lane, asking "is this shell script correct", does not.
 *
 * The bundle is fetched, not committed, and pinned: a release other than the pinned one, or none,
 * is a crash naming the one command that installs it. The lane checks the whole project whatever
 * `-p` names, because its rules are cross-file: a record entry is judged against every workflow.
 *
 * @internal
 */
final readonly class ActionsQaTool implements ToolInterface
{
    /** The lane's stable identifier, printed on failure; owned by ActionsQaBinary. */
    public const string IDENTIFIER = ActionsQaBinary::IDENTIFIER;

    /** The directory GitHub reads workflows from; a project tracking nothing there has none to check. */
    private const string WORKFLOWS = '.github/workflows';

    /** actions-qa's exit code for a run that found something. */
    private const int EXIT_FINDINGS = 1;

    /** What every other exit code of `check` means: none of them is a verdict about the workflows. */
    private const array NO_VERDICT = [
        2 => 'actions-qa-ci refused to check the project (%s)',
        3 => 'actions-qa-ci hit a toolchain fault (%s)',
        4 => 'actions-qa-ci reached no verdict (%s)',
    ];

    /** The summary of an exit code actions-qa does not document. */
    private const string CRASHED = 'actions-qa-ci crashed (%s)';

    /** The summary of every way the install can be unusable. */
    private const string BROKEN_INSTALL = 'actions-qa-ci is not installed at the pinned release';

    /**
     * @param string      $os      PHP_OS_FAMILY of the host the bundle runs on
     * @param string|null $machine php_uname('m') of that host; this host's when null
     */
    public function __construct(
        private string $os = \PHP_OS_FAMILY,
        private ?string $machine = null,
    ) {
    }

    public function name(): string
    {
        return 'actionsQa';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $workflows = $this->quiet($context, 'git', 'ls-files', '--cached', '--', self::WORKFLOWS);
        if (!$workflows->succeeded()) {
            return ToolResultDto::skipped('the project is not a git work tree, so there are no tracked workflows to check');
        }

        if ('' === trim($workflows->stdout)) {
            return ToolResultDto::skipped('the project tracks nothing under ' . self::WORKFLOWS . ', so there is no GitHub Actions workflow to check');
        }

        $machine = $this->machine ?? php_uname('m');
        if (null === ActionsQaBinary::platformFor($this->os, $machine)) {
            return $this->reportUnsupportedHost($context, $machine);
        }

        $libraryRoot = $context->config->paths->libraryRoot;
        $program     = ActionsQaBinary::path($libraryRoot);
        if (!is_executable($program)) {
            $context->writeln(\sprintf('actions-qa-ci %s is not installed: %s is missing or not executable.', ActionsQaBinary::VERSION, $program));

            return $this->reportInstallCommand($context, $libraryRoot);
        }

        $probe    = $this->quiet($context, $program, 'version', '--json');
        $reported = $probe->succeeded() ? ActionsQaBinary::reportedVersion($probe->stdout) : null;
        if (ActionsQaBinary::VERSION !== $reported) {
            $context->writeln(\sprintf(
                'The installed actions-qa-ci reports %s but this checkout pins %s.',
                $reported ?? \sprintf('nothing readable (%s)', $probe->describeExit()),
                ActionsQaBinary::VERSION,
            ));
            $context->writeln('One pinned release has to run in every environment, or QA and CI are not the same check.');

            return $this->reportInstallCommand($context, $libraryRoot);
        }

        return $this->check($context, $program);
    }

    private function check(ToolContext $context, string $program): ToolResultDto
    {
        $result = $this->quiet($context, $program, 'check', '--format', 'agent');
        $context->writeln(rtrim($result->output, "\n"));

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        if (self::EXIT_FINDINGS === $result->exitCode && null === $result->signal) {
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('actions-qa-ci reported findings');
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed(\sprintf(
            null === $result->signal ? self::NO_VERDICT[$result->exitCode] ?? self::CRASHED : self::CRASHED,
            $result->describeExit(),
        ));
    }

    /** A command run in the project root with its output captured rather than streamed. */
    private function quiet(ToolContext $context, string ...$command): ProcessResultDto
    {
        return $context->processes->run(new ProcessSpecDto(
            array_values($command),
            $context->config->paths->projectRoot,
            streamOutput: false,
        ));
    }

    private function reportInstallCommand(ToolContext $context, string $libraryRoot): ToolResultDto
    {
        $context->writeln('Install it with:');
        $context->writeln('  ' . ActionsQaBinary::installCommand($libraryRoot));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed(self::BROKEN_INSTALL);
    }

    /**
     * The release publishes Linux bundles for x86_64 and aarch64 only, and exec'ing either anywhere
     * else fails in a way that reads as a broken install, so the host is named instead.
     */
    private function reportUnsupportedHost(ToolContext $context, string $machine): ToolResultDto
    {
        $context->writeln(\sprintf('actions-qa-ci publishes no bundle for this host (%s %s).', $this->os, $machine));
        $context->writeln(\sprintf('The lane runs on Linux with an x86_64 (%s) or aarch64 (%s) CPU.', ActionsQaBinary::PLATFORM_AMD64, ActionsQaBinary::PLATFORM_ARM64));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::crashed('actions-qa-ci has no bundle for this host');
    }
}
