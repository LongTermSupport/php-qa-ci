<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak;

/**
 * @api
 */
final class ApiLeaksViaParam
{
    public function consume(ExposedInternalDto $dto): string
    {
        return $dto->value;
    }
}
