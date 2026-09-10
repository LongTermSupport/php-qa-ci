<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ComposerPluginOutside;

final class NotAPlugin
{
    public function run(string $command): void
    {
        $output = [];
        \Safe\exec($command, $output);
    }
}
