<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Cli;

use LTS\PHPQA\Pipeline\Cli\Dto\RunRequestDto;
use LTS\PHPQA\Pipeline\Cli\Exception\UsageException;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;

/**
 * The `qa` command line: `[-t tool] [-p path] [--json] [-h] [path]`.
 *
 *  - `-t <token>` selects one tool or phase runner by any registered alias.
 *  - `-p <path>` scopes a path-supporting tool; a single bare argument is
 *    taken as the path when -p is absent.
 *  - `--json` needs -t and a tool that supports it (phpstan).
 *  - `-h` prints the usage and exits 1, as does any unknown option.
 *
 * @internal
 */
final readonly class ArgumentsParser
{
    public const string AGENT_MODE_OPTION = '--agent-mode';

    public const string AGENT_MODE_ENV = 'PHPQACI_AGENT_MODE';

    public function __construct(
        private ToolRegistry $registry,
        private string $binDir,
        private bool $agentModeFromEnvironment = false,
    ) {
    }

    /** @param string ...$argv the arguments after the script name */
    public function parse(string ...$argv): RunRequestDto
    {
        $argv  = array_values($argv);
        $json  = false;
        $agent = $this->agentModeFromEnvironment;
        $tool  = null;
        $path  = null;
        $bare  = [];
        $count = \count($argv);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $argv[$i];
            switch ($arg) {
                case '--json':
                    $json = true;

                    break;

                case self::AGENT_MODE_OPTION:
                    $agent = true;

                    break;

                case '-h':
                    throw UsageException::help();
                case '-t':
                case '-p':
                    $value = $argv[$i + 1] ?? null;
                    if (null === $value) {
                        throw UsageException::withUsage(\sprintf("\nERROR\nOption %s requires an argument\n", $arg));
                    }

                    ++$i;
                    if ('-t' === $arg) {
                        $tool = $value;
                    } else {
                        $path = $value;
                    }

                    break;

                default:
                    if (str_starts_with($arg, '-t') && \strlen($arg) > 2) {
                        $tool = substr($arg, 2);
                    } elseif (str_starts_with($arg, '-p') && \strlen($arg) > 2) {
                        $path = substr($arg, 2);
                    } elseif (str_starts_with($arg, '-')) {
                        throw $this->unsupportedArgument($arg);
                    } else {
                        $bare[] = $arg;
                    }
            }
        }

        if (null === $path && [] !== $bare) {
            if (\count($bare) > 1) {
                throw UsageException::plain(\sprintf(
                    "\nERROR:\nMultiple paths not supported: %s\n\nSpecify a single path: %s/qa -t toolname path/to/check\n",
                    implode(' ', $bare),
                    $this->binDir,
                ));
            }

            $path = $bare[0];
        } elseif ([] !== $bare) {
            throw $this->unsupportedArgument($bare[0]);
        }

        $selectedToken = null;
        $resolved      = null;
        if (null !== $tool) {
            if (null !== $path && !$this->registry->supportsPaths($tool)) {
                throw UsageException::plain(\sprintf(
                    "\nERROR:\nTool '%s' does not support path-specific execution.\n\nThis tool operates on the entire project regardless of path specification.\nIf you expected a quick test run, this would trigger a full project scan.\n\nTools that support path specification:\n%s\n\nTo run this tool on the entire project: %s/qa -t %s\n",
                    $tool,
                    implode("\n", array_map(static fn (string $t): string => '  ' . $t, $this->registry->pathSupportingTokens())),
                    $this->binDir,
                    $tool,
                ));
            }

            try {
                $definition = $this->registry->resolve($tool);
            } catch (UnknownToolException $unknownToolException) {
                throw UsageException::withUsage(\sprintf("\nERROR:\n%s\n", $unknownToolException->getMessage()));
            }

            $resolved      = $definition->target ?? $definition->name;
            $selectedToken = null !== $definition->target ? $tool : null;
        }

        if ($json) {
            if (null === $resolved) {
                throw UsageException::plain("\nERROR: --json requires a single tool (-t)\n  Example: vendor/bin/qa -t phpstan --json\n");
            }

            if (!$this->registry->definition($resolved)->supportsJson) {
                throw UsageException::plain(\sprintf(
                    "\nERROR: --json is not yet supported for '%s'\n\nTools with --json support:\n  phpstan    (native --error-format=json)\n\nRun without --json, or use a supported tool.\n",
                    $resolved,
                ));
            }
        }

        if ($agent) {
            $this->assertAgentModeIsPossible($resolved);
        }

        return new RunRequestDto($resolved, $path, $selectedToken, $agent);
    }

    /**
     * Agent mode is a single-lane reporting mode, so it needs a tool, and that
     * tool has to be one that writes the per-file reports. Anything else is
     * refused here rather than degraded to ordinary output further down: a
     * caller that asked for a report and got a console transcript instead has
     * no way to tell that from a tool with nothing to say.
     */
    private function assertAgentModeIsPossible(?string $resolved): void
    {
        $supported = $this->registry->agentModeTools();
        $listing   = "\nTools with --agent-mode support:\n" . implode("\n", array_map(static fn (string $t): string => '  ' . $t, $supported)) . "\n";

        if (null === $resolved) {
            throw UsageException::plain(\sprintf(
                "\nERROR: %s requires a single tool (-t)\n  Example: %s/qa %s -t phpstan -p src/Kernel.php\n%s",
                self::AGENT_MODE_OPTION,
                $this->binDir,
                self::AGENT_MODE_OPTION,
                $listing,
            ));
        }

        if ($this->registry->definition($resolved)->supportsAgentMode) {
            return;
        }

        throw UsageException::plain(\sprintf(
            "\nERROR: %s is not supported for '%s'\n\nThat tool writes no per-file report, so agent mode would have nothing to point you at.\n%s\nRun it without %s, or select a tool above.\n",
            self::AGENT_MODE_OPTION,
            $resolved,
            $listing,
            self::AGENT_MODE_OPTION,
        ));
    }

    public function usage(): string
    {
        $lines = [
            'Usage:',
            $this->binDir . '/qa [-t tool to run ] [ -p path to scan ] [ --json ] [ --agent-mode ]',
            '',
            'Defaults to using all tools and scanning whole project based on platform',
            '',
            ' - use -h to see this help',
            '',
            ' - use -p to specify a specific path to scan',
            '',
            ' - use --json to get structured JSON output (supported tools only)',
            '',
            ' - use --agent-mode for a terse stdout plus a per-file JSON report under var/qa',
            '   (needs -t and a supporting tool; PHPQACI_AGENT_MODE=1 is the same thing)',
            '',
            ' - use -t to specify a single tool:',
        ];
        foreach ($this->registry->usageLines() as $line) {
            $lines[] = '     ' . $line;
        }

        return implode("\n", $lines) . "\n";
    }

    private function unsupportedArgument(string $arg): UsageException
    {
        return UsageException::plain(\sprintf(
            "\nERROR:\nUnsupported argument: %s\n\nThe QA pipeline only supports path specification.\nUse: %s/qa -t toolname -p path/to/check\nOr:  %s/qa -t toolname path/to/check (automatic -p)\n",
            $arg,
            $this->binDir,
            $this->binDir,
        ));
    }
}
