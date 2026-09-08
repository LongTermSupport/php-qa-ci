<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

/**
 * The project platform the pipeline detected. Platform-specific tools
 * (Symfony's twig/yaml linters) run only on the matching platform.
 *
 * @internal
 */
enum PlatformEnum: string
{
    case Generic = 'generic';
    case Symfony = 'symfony';
}
