<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak;

/**
 * An @api class may legitimately have members that are themselves @internal — a
 * factory-only constructor, or an internal mapper that bridges to a generated
 * type. Those members are NOT part of the consumer surface, so exposing an
 * @internal type through them is not a leak. Only non-@internal public members
 * count.
 *
 * @api
 */
final class ApiWithInternalMembers
{
    /**
     * @internal constructed only by the factory, never by a consumer
     */
    public function __construct(private readonly ExposedInternalDto $dep)
    {
    }

    /**
     * @internal internal mapping bridge, not for consumers
     */
    public function fromInternal(ExposedInternalDto $dto): string
    {
        return $dto->value;
    }

    public function name(): string
    {
        return $this->dep->value;
    }
}
