<?php

declare(strict_types=1);

namespace ArkitectFixture\Dto;

// Violates the Dto SUFFIX rule: it sits in a Dto namespace segment but is not
// named *Dto (should be FooDto).
final readonly class Foo
{
}
