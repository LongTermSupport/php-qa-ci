<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

use LTS\PHPQA\Pipeline\Config\ComposerBinDirReader;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Declares the hooks daemon's `subagent_full_qa_blocker` in a consuming
 * project, so a sub-agent's full pipeline run is denied and the full gate
 * stays with the coordinating session. Runs on every composer install/update
 * (scripts/deploy-skills.bash, through bin/hooks-daemon-full-qa-blocker).
 *
 * Nothing here can fail an install: every outcome is reported and the exit
 * code stays 0. The change is written only after the daemon's own
 * `config-validate` has accepted the exact candidate text, and a daemon older
 * than the handler is left alone.
 *
 * @api bin/hooks-daemon-full-qa-blocker is the caller
 */
final readonly class FullQaBlockerConfigurator
{
    /** The first hooks-daemon release that ships subagent_full_qa_blocker. */
    public const string MINIMUM_DAEMON_VERSION = '3.67.0';

    /** The candidate sits beside the config, so the daemon validates it from the same place. */
    public const string CANDIDATE_SUFFIX = '.php-qa-ci-candidate';

    /** The daemon install, beside its config in `.claude/`. */
    private const string DAEMON_DIR = 'hooks-daemon';

    /** The daemon's CLI, relative to its install. */
    private const string DAEMON_CLI = 'bin/hooks-daemon';

    /** Where a person finds the recommended block and the reasoning behind it. */
    private const string DOCS = 'php-qa-ci docs/hooks-daemon-full-qa-blocker.md';

    /** A cold daemon venv can take a while to start; a hung one must not hang composer. */
    private const float VALIDATE_TIMEOUT_SECONDS = 120.0;

    /** Matches the deploy script's own output indent. */
    private const string INDENT = '  ';

    public function __construct(
        private ProcessRunnerInterface $processes,
        private OutputInterface $output,
        private FullQaBlockerYamlEditor $editor = new FullQaBlockerYamlEditor(),
        private HooksDaemonVersionReader $versions = new HooksDaemonVersionReader(),
        private ComposerBinDirReader $binDirs = new ComposerBinDirReader(),
    ) {
    }

    public static function main(string $projectRoot, string $daemonConfig): int
    {
        return new self(new SymfonyProcessRunner(new NullOutput()), new ConsoleOutput())->run($projectRoot, $daemonConfig);
    }

    /** @return int the exit code: 0 whatever was decided, 1 only when the attempt itself broke */
    public function run(string $projectRoot, string $daemonConfig): int
    {
        try {
            $this->configure($projectRoot, $daemonConfig);
        } catch (Throwable $throwable) {
            $this->output->writeln(self::INDENT . '⚠️  subagent_full_qa_blocker not configured: ' . $throwable->getMessage(), OutputInterface::OUTPUT_RAW);

            return 1;
        }

        return 0;
    }

    public function configure(string $projectRoot, string $daemonConfig): ConfigureOutcomeEnum
    {
        if (!is_file($daemonConfig)) {
            return $this->report(ConfigureOutcomeEnum::NoDaemonConfig, \sprintf('ℹ️  no hooks-daemon config at %s — subagent_full_qa_blocker not configured', $daemonConfig));
        }

        $daemonRoot = \dirname($daemonConfig) . '/' . self::DAEMON_DIR;
        $version    = $this->versions->read($daemonRoot);
        if (null === $version) {
            return $this->report(ConfigureOutcomeEnum::NoDaemonInstall, \sprintf('ℹ️  no hooks-daemon install with a readable version under %s — subagent_full_qa_blocker not configured', $daemonRoot));
        }

        if (!$this->versions->isAtLeast($version, self::MINIMUM_DAEMON_VERSION)) {
            return $this->report(ConfigureOutcomeEnum::DaemonTooOld, \sprintf(
                'ℹ️  hooks-daemon %s predates subagent_full_qa_blocker (it needs %s) — not configured; upgrade the daemon, then re-run composer install',
                $version,
                self::MINIMUM_DAEMON_VERSION,
            ));
        }

        $edit = $this->editor->apply(\Safe\file_get_contents($daemonConfig), $this->binDirs->read($projectRoot));

        return match ($edit->outcome) {
            YamlEditOutcomeEnum::Unchanged                              => $this->report(ConfigureOutcomeEnum::Unchanged, '✓ subagent_full_qa_blocker already configured (the full pipeline is the coordinating session\'s)'),
            YamlEditOutcomeEnum::DisabledByProject                      => $this->report(ConfigureOutcomeEnum::DisabledByProject, \sprintf('ℹ️  subagent_full_qa_blocker is disabled in %s — left as the project set it', $daemonConfig)),
            YamlEditOutcomeEnum::ProjectOwned                           => $this->report(ConfigureOutcomeEnum::ProjectOwned, \sprintf(
                'ℹ️  subagent_full_qa_blocker in %s has no php-qa-ci marker, so it is the project\'s own — left untouched (the recommended block is in %s)',
                $daemonConfig,
                self::DOCS,
            )),
            YamlEditOutcomeEnum::Unsupported                            => $this->report(ConfigureOutcomeEnum::Unsupported, \sprintf(
                '⚠️  %s cannot be edited safely as text (a non-empty flow mapping, or CRLF line endings) — add subagent_full_qa_blocker by hand, see %s',
                $daemonConfig,
                self::DOCS,
            )),
            YamlEditOutcomeEnum::Inserted, YamlEditOutcomeEnum::Updated => $this->validateThenWrite($projectRoot, $daemonRoot, $daemonConfig, $edit->yaml),
        };
    }

    private function validateThenWrite(string $projectRoot, string $daemonRoot, string $daemonConfig, string $yaml): ConfigureOutcomeEnum
    {
        $cli = $daemonRoot . '/' . self::DAEMON_CLI;
        if (!is_file($cli)) {
            return $this->report(ConfigureOutcomeEnum::CannotValidate, \sprintf('⚠️  %s is missing, so config-validate cannot check the change — subagent_full_qa_blocker not configured', $cli));
        }

        $candidate = $daemonConfig . self::CANDIDATE_SUFFIX;

        try {
            \Safe\file_put_contents($candidate, $yaml);
            $result = $this->processes->run(new ProcessSpecDto(
                command: [$cli, 'config-validate', $candidate],
                cwd: $projectRoot,
                timeout: self::VALIDATE_TIMEOUT_SECONDS,
                streamOutput: false,
            ));
            if (!$result->succeeded()) {
                $this->report(ConfigureOutcomeEnum::Rejected, \sprintf('⚠️  config-validate rejected the change (exit %d), so %s is left untouched:', $result->exitCode, $daemonConfig));
                foreach (explode("\n", rtrim($result->output)) as $line) {
                    $this->output->writeln(self::INDENT . self::INDENT . $line, OutputInterface::OUTPUT_RAW);
                }

                return ConfigureOutcomeEnum::Rejected;
            }

            \Safe\chmod($candidate, \Safe\fileperms($daemonConfig) & 0o777);
            \Safe\rename($candidate, $daemonConfig);
        } finally {
            if (is_file($candidate)) {
                \Safe\unlink($candidate);
            }
        }

        $this->report(ConfigureOutcomeEnum::Written, \sprintf('✓ subagent_full_qa_blocker configured in %s: a sub-agent can no longer run the full pipeline', $daemonConfig));

        return $this->report(ConfigureOutcomeEnum::Written, \sprintf(
            '↻ restart the hooks daemon to load it: %s restart (a running daemon keeps its old config until then)',
            str_starts_with($cli, $projectRoot . '/') ? substr($cli, \strlen($projectRoot) + 1) : $cli,
        ));
    }

    private function report(ConfigureOutcomeEnum $outcome, string $message): ConfigureOutcomeEnum
    {
        $this->output->writeln(self::INDENT . $message, OutputInterface::OUTPUT_RAW);

        return $outcome;
    }
}
