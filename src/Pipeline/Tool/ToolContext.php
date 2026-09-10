<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Tool;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\ProcessRunnerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Everything a tool needs to run: the resolved configuration and the shared
 * services. Handed to every ToolInterface::run().
 *
 * The two output streams are not interchangeable. `$output` takes everything a
 * human reads, and in `--json` mode it is stderr so it cannot corrupt the
 * report. `$stdout` is the real stdout and takes structured output only.
 *
 * @api
 */
final readonly class ToolContext
{
    public function __construct(
        public QaConfigDto $config,
        public ConfigPathResolver $configPaths,
        public ProcessRunnerInterface $processes,
        public PhpInvoker $php,
        public LogArchiver $logs,
        public OutputInterface $output,
        public OutputInterface $stdout,
    ) {
    }

    /** Convenience: the resolved path of a config file (project override or shipped default). */
    public function configPath(string $relativePath): string
    {
        return $this->configPaths->resolve($relativePath);
    }

    /** A per-tool log directory under var/qa, created on first use. */
    public function logDir(string $name): string
    {
        $dir = $this->config->paths->varDir . '/' . $name;
        if (!is_dir($dir)) {
            \Safe\mkdir($dir, 0o777, true);
        }

        return $dir;
    }

    public function writeln(string $line = ''): void
    {
        $this->output->writeln($line);
    }

    /** Print the standard identifier trailer a failing lane ends with. */
    public function writeIdentifier(string $identifier): void
    {
        $this->output->writeln('');
        $this->output->writeln(\sprintf('🪪  %s  (vendor/bin/rule-doc %s)', $identifier, $identifier));
    }
}
