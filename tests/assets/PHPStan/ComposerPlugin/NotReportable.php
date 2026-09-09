<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ComposerPlugin;

final class NotReportable
{
    public function run(string $command): string
    {
        $quoted = escapeshellarg($command);
        if (\strlen($quoted) > 0 && \Composer\Semver\Semver::satisfies('1.0.0', '^1.0')) {
            return $quoted;
        }

        return \dirname($command);
    }
}
