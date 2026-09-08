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
    public function __construct(private ToolRegistry $registry, private string $binDir)
    {
    }

    /** @param string ...$argv the arguments after the script name */
    public function parse(string ...$argv): RunRequestDto
    {
        $argv  = array_values($argv);
        $json  = false;
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

        return new RunRequestDto($resolved, $path, $json, $selectedToken);
    }

    public function usage(): string
    {
        $lines = [
            'Usage:',
            $this->binDir . '/qa [-t tool to run ] [ -p path to scan ] [ --json ]',
            '',
            'Defaults to using all tools and scanning whole project based on platform',
            '',
            ' - use -h to see this help',
            '',
            ' - use -p to specify a specific path to scan',
            '',
            ' - use --json to get structured JSON output (supported tools only)',
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
