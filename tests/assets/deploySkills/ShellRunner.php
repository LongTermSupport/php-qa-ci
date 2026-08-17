<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\DeploySkills;

use RuntimeException;

/**
 * Test-support helper for running a bash command and capturing its output.
 *
 * Like its sibling DeployProcessRunner it lives under tests/assets/ — a
 * fixtures path excluded from static analysis — so the deliberate proc_open
 * usage is never analysed, and the test class that calls it stays free of the
 * loosely-typed by-reference output params that exec() would introduce.
 *
 * Used for two things: reading an array back out of the bash deploy manifest
 * (the manifest IS bash, so sourcing it is the only reading that cannot drift
 * from what the deploy actually does), and small fixture setup commands.
 */
final class ShellRunner
{
    /**
     * @return array{exit: int, output: string}
     */
    public static function run(string $command, ?string $cwd = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes   = [];
        $process = \proc_open(['bash', '-c', $command], $descriptors, $pipes, $cwd);
        if (!\is_resource($process)) {
            throw new RuntimeException('Failed to run: ' . $command);
        }

        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($process);

        if (false === $stdout) {
            $stdout = '';
        }
        if (false === $stderr) {
            $stderr = '';
        }

        return [
            'exit'   => $exit,
            'output' => $stdout . $stderr,
        ];
    }

    /**
     * Source the bash deploy manifest and read one of its arrays back.
     *
     * @return list<string>
     */
    public static function readManifestArray(string $manifestPath, string $arrayName): array
    {
        $command = \sprintf(
            'set -euo pipefail; source %s && printf "%%s\n" "${%s[@]}"',
            \escapeshellarg($manifestPath),
            $arrayName,
        );

        $result = self::run($command);
        if (0 !== $result['exit']) {
            throw new RuntimeException(\sprintf(
                'Could not read %s from %s: %s',
                $arrayName,
                $manifestPath,
                $result['output'],
            ));
        }

        $values = [];
        foreach (\explode("\n", $result['output']) as $line) {
            $line = \trim($line);
            if ('' !== $line) {
                $values[] = $line;
            }
        }

        return $values;
    }
}
