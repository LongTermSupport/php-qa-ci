<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

/**
 * Symfony is recognised by its `symfony.lock`; everything else is generic.
 *
 * @internal
 */
final readonly class PlatformDetector
{
    public function detect(string $projectRoot): PlatformEnum
    {
        if (is_file($projectRoot . '/symfony.lock')) {
            return PlatformEnum::Symfony;
        }

        return PlatformEnum::Generic;
    }
}
