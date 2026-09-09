<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\BinDirTool;

use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;

final class NotReportable
{
    private const string PHAR = 'phpcpd.phar';

    public string $binDir = '/elsewhere';

    public function phpunit(ProjectPathsDto $paths): string
    {
        return $paths->binDir . '/phpunit';
    }

    public function paratest(ProjectPathsDto $paths): string
    {
        return $paths->binDir . '/paratest';
    }

    public function phar(ProjectPathsDto $paths): string
    {
        return $paths->pharDir . '/' . self::PHAR;
    }

    public function unrelatedProperty(): string
    {
        return $this->binDir . '/anything';
    }
}
