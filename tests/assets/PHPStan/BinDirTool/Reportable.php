<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\BinDirTool;

use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;

final class Reportable
{
    private const string BINARY = 'composer-dependency-analyser';

    public function literal(ProjectPathsDto $paths): string
    {
        return $paths->binDir . '/parallel-lint';
    }

    public function constant(ProjectPathsDto $paths): string
    {
        return $paths->binDir . '/' . self::BINARY;
    }

    public function dynamic(ProjectPathsDto $paths, string $tool): string
    {
        return $paths->binDir . '/' . $tool;
    }
}
