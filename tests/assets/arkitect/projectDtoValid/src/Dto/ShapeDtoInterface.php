<?php

declare(strict_types=1);

namespace ArkitectFixture\Dto;

// The kind carve-out, pinned. An interface inside a Dto namespace keeps its own
// *Interface suffix and must NOT be dragged to *Dto / final / readonly by the
// Dto rules — Arkitect ignores appliesTo() in should(), so the andThat(IsNot*)
// guards in the default tier are the only thing preventing that. Deleting them
// turns this file into three violations.
interface ShapeDtoInterface
{
}
