<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiOrInternal;

/**
 * An enum on the supported public contract — a class-like that InClassNode fires
 * for, classified @api.
 *
 * @api
 */
enum ClassifiedEnum: string
{
    case One = 'one';
    case Two = 'two';
}
