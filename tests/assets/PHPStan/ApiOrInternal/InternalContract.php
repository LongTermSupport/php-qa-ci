<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiOrInternal;

/**
 * An interface that is not part of the public contract — a class-like that
 * InClassNode fires for, classified @internal.
 *
 * @internal
 */
interface InternalContract
{
}
