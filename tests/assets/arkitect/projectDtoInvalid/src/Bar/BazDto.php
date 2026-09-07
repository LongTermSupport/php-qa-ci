<?php

declare(strict_types=1);

namespace ArkitectFixture\Bar;

// Violates the Dto NAMESPACE rule: correctly suffixed, but stranded outside a
// Dto namespace segment (should be ArkitectFixture\Dto\BazDto).
final readonly class BazDto
{
}
