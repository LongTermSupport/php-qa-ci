<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\DevNamespace\Command\Dev;

/**
 * Dev-only tooling in a `Dev` sub-namespace of a shipped autoload root: the
 * originating shape, a maintainer console command under `src/Command/Dev/`.
 */
final class SandboxPushCommand
{
    public function push(): void
    {
    }
}
