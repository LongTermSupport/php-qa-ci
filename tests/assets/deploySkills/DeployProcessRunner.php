<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\DeploySkills;

use RuntimeException;

/**
 * Test-support helper that launches scripts/deploy-skills.bash as a child
 * process and captures its combined stdout/stderr and exit code.
 *
 * It lives under tests/assets/ (a fixtures path excluded from the
 * static-analysis pathsToIgnore, so its deliberate proc_open usage is never
 * analysed) and is autoloaded via its own composer.json autoload-dev PSR-4
 * entry, following the same pattern as the PHPStan rule fixtures.
 *
 * The child is launched with its working directory set to the consumer project
 * root — the same working directory the SkillsDeployPlugin uses when composer
 * invokes the script, which the "migrate old hook names" step (a CWD-relative
 * settings.json read) depends on.
 */
final class DeployProcessRunner
{
    /**
     * @return array{exit: int, output: string}
     */
    public static function run(string $scriptPath, string $qaciPath, string $consumerRoot): array
    {
        $command = ['bash', $scriptPath, $qaciPath, $consumerRoot];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes   = [];
        $process = \proc_open($command, $descriptors, $pipes, $consumerRoot);
        if (!\is_resource($process)) {
            throw new RuntimeException('Failed to launch ' . $scriptPath);
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
            'output' => $stdout . "\n" . $stderr,
        ];
    }
}
