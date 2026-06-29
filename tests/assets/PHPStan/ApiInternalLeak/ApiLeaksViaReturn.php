<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak;

/**
 * @api
 */
final class ApiLeaksViaReturn
{
    public function fetch(): ExposedInternalDto
    {
        return new ExposedInternalDto('x');
    }
}
