<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ComposerPlugin;

use function Safe\json_decode;

final class Reportable
{
    public function run(string $command): void
    {
        $output = [];
        \Safe\exec($command, $output);
        json_decode('{}');
        $this->helper(\Safe\file_get_contents('/dev/null'));
    }

    private function helper(string $contents): void
    {
        \strlen($contents);
    }
}
